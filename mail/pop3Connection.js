const EventEmitter = require("events");
const crypto = require("crypto");
const encoding = require("encoding-japanese");
const POP3State = Object.freeze({"AUTHORIZATION":1, "TRANSACTION":2, "UPDATE":3});

// The Mobile Trainer has room for only a short title, so the Subject handed
// to the game is capped at this many characters -- the ellipsis counts, so a
// truncated subject is 7 characters plus "...". Nothing is lost: only the
// copy sent over POP3 is rewritten, and the webmail reads the original row.
const SUBJECT_MAX_CHARS = 10;
const SUBJECT_ELLIPSIS = "...";

// Derives the POP3-specific subkey from a device_auth_key (the same key
// used for HTTP device-auth, see DeviceAuthUtil.php) instead of using it
// raw for XAPOP, so a signature computed under one protocol can never be
// replayed as a valid signature under the other.
function xapopSubkey(deviceAuthKey) {
	return crypto.createHmac("sha256", deviceAuthKey).update("pop3-xapop").digest();
}

class POP3Connection extends EventEmitter {
	constructor(server, sock) {
		super();
		
		this._server = server;
		this._socket = sock;
		
		this._inputBuffer = "";
		this._isProcessingInput = false;
		this._isBusy = true;

		this._initSession();

		this._socket.on('data', data => this._onData(data));
		this._socket.on('close', () => this._onClose());
		this._socket.on('error', error => this._onError(error));

		// One nonce per TCP connection (not per account -- the server
		// doesn't know which account is connecting until XAPOP/USER
		// arrives), bound into every XAPOP signature so a signature can't
		// be replayed against a different connection.
		this._nonce = crypto.randomBytes(16).toString("hex");
		this._send(true, "service ready " + this._nonce + "@reon.dion.ne.jp");
		this._isBusy = false;
	}

	_initSession() {
		this._user = null;
		this._userId = null;
		this._state = POP3State.AUTHORIZATION;
		this._maildrop = [];
	}
	
	_send(success, data) {
        if (this._socket && this._socket.writable) {
            this._socket.write((success ? "+OK " : "-ERR ") + data + "\r\n");
        }
    }
	
	close() {
        if (!this._socket.destroyed && this._socket.writable) {
            this._socket.end();
        }
		this.emit("disconnect", this, this._socket.remoteAddress, this._socket.remotePort);
    }
	
	_onClose() {
		this.close();
	}
	
	_onData(data) {
		this._inputBuffer += data;
		// Start processing the input buffer if not already
		if (!this._isProcessingInput) {
			this._isProcessingInput = true;
			// While there are commands
			while (this._inputBuffer.indexOf("\r\n") != -1) {
				// Make sure commands run one after another and not in parallel
				if (!this._isBusy) {
					this._isBusy = true;
					try {
						this._onCommand(this._inputBuffer.substring(0, this._inputBuffer.indexOf("\r\n") + 2));
					} catch (error) {
						this._onError(error);
					} finally {
						// Remove the processed command from buffer
						this._inputBuffer = this._inputBuffer.substring(this._inputBuffer.indexOf("\r\n") + 2);
						this._isBusy = false;
					}
				}
			}
			this._isProcessingInput = false;
		}
	}
	
	_onError(error) {
		this.emit("error", error);
		this._send(false, "server error");
	}
	
	_onCommand(command) {
		let commandName = command.indexOf(" ") == -1 ? command.substring(0, command.indexOf("\r\n")) : command.substring(0, command.indexOf(" "));
		this.emit("command", commandName, this._user, this._socket.remoteAddress, this._socket.remotePort);
		if (this._isCommandSupported(commandName)) {
			this["_commandHandler_" + commandName].call(this, command.indexOf(" ") == -1 ? null : command.substring(command.indexOf(" ") + 1, command.indexOf("\r\n")));
		} else {
			this._send(false, "command not recognized");
		}
	}
	
    _isCommandSupported(command) {
        return typeof this["_commandHandler_" + command] === "function";
    }
	
	_commandHandler_USER(param) {
		if (this._state == POP3State.AUTHORIZATION) {
			if (param != null && param != "") {
				this._user = param;
				this._send(true, "user accepted");
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
    }
	
	_commandHandler_PASS(param) {
        if (this._state == POP3State.AUTHORIZATION) {
			if (param != null && param != "") {
				if (this._user != null) {
					this._server.mysql.query("select id, log_in_password from sys_users where dion_email_local = ? limit 1", [this._user], function (error, results, fields) {
						if (error) {
							this._onError(error);
						} else {
							if (results.length > 0) {
								// Check password
								if (param === results[0]["log_in_password"]) {
									this._userId = results[0]["id"];
									this._loadMaildrop(results[0]["id"], "pass accepted");
								} else {
									this._send(false, "invalid user or pass");
								}
							} else {
								this._send(false, "invalid user or pass");
							}
						}
					}.bind(this));
				} else {
					this._send(false, "no user set");
				}
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
    }
	
	// XAPOP <ppp_id> <hmac-sha256-hex> -- replaces the entire USER+PASS pair
	// for a device that already holds a device_auth_key (see
	// DeviceAuthUtil.php): libmobile never has the game's real mailbox
	// password available outside the very first session, so this proves
	// identity with the same durable key device-auth already uses instead.
	// Signature = HMAC-SHA256(xapopSubkey(device_auth_key), ppp_id + "|" +
	// this connection's greeting nonce). Failure leaves the connection in
	// AUTHORIZATION exactly as if nothing had been sent yet, so a caller
	// whose device_auth_key was revoked/never provisioned can fall back to
	// classic USER/PASS on the same connection with no special handling.
	_commandHandler_XAPOP(param) {
		if (this._state == POP3State.AUTHORIZATION) {
			let params = param != null ? param.split(" ") : [];
			if (params.length == 2 && /^g[0-9]{9}$/.test(params[0]) && /^[0-9a-f]{64}$/.test(params[1])) {
				let pppId = params[0];
				let sig = Buffer.from(params[1], "hex");
				this._server.mysql.query(
					"select u.id, a.device_auth_key from sys_users u inner join sys_device_authorization a on a.user_id = u.id where u.dion_ppp_id = ?",
					[pppId],
					function (error, results, fields) {
						if (error) {
							this._onError(error);
							return;
						}
						if (results.length === 0) {
							this._send(false, "invalid user or pass");
							return;
						}
						let userId = results[0]["id"];
						let subkey = xapopSubkey(results[0]["device_auth_key"]);
						let expected = crypto.createHmac("sha256", subkey).update(pppId + "|" + this._nonce).digest();
						if (!crypto.timingSafeEqual(expected, sig)) {
							this._send(false, "invalid user or pass");
							return;
						}

						this._userId = userId;
						this._loadMaildrop(userId, "pass accepted");
					}.bind(this)
				);
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
	}

	// XPROVISION (no params) -- returns this account's device_auth_key,
	// generating one if it doesn't have one yet, so a device that just
	// authenticated with the real USER/PASS (the one session where it
	// still has the real password) can bootstrap XAPOP for every session
	// after this one. Deliberately mirrors DeviceAuthUtil::keyForDownload's
	// find-or-create, but without touching counter/authorized/
	// authorized_until -- those belong to the separate HTTP device-auth
	// (outbound relay) flow and this must not disturb them.
	_commandHandler_XPROVISION(param) {
		if (this._state == POP3State.TRANSACTION) {
			this._server.mysql.query("select device_auth_key from sys_device_authorization where user_id = ?", [this._userId], function (error, results, fields) {
				if (error) {
					this._onError(error);
					return;
				}
				if (results.length > 0) {
					this._send(true, results[0]["device_auth_key"].toString("hex"));
					return;
				}
				let key = crypto.randomBytes(32);
				this._server.mysql.query("insert into sys_device_authorization (user_id, device_auth_key) values (?, ?)", [this._userId, key], function (error, results, fields) {
					if (error) {
						this._onError(error);
						return;
					}
					this._send(true, key.toString("hex"));
				}.bind(this));
			}.bind(this));
		} else {
			this._send(false, "command not allowed");
		}
	}

	_commandHandler_QUIT(param) {
		switch (this._state) {
			case POP3State.AUTHORIZATION:
			this._send(true, "bye");
			this.close();
			break;
			
			case POP3State.TRANSACTION:
			this._state = POP3State.UPDATE;
			
			let deleteList = [];
			for (let i = 0; i < this._maildrop.length; i++) {
				if (this._maildrop[i]["deleted"]) deleteList.push(this._maildrop[i]["id"]);
			}
			if (deleteList.length > 0) {
				// Two different deletes, because two different things are being
				// deleted.
				//
				// A person's mail is moved to the trash rather than removed:
				// the Mobile Trainer can delete a message without ever
				// downloading it, so a hard delete destroyed mail nothing had
				// read. A purge job clears the trash after its retention
				// window instead.
				//
				// A game's own mail that the game has actually collected is
				// removed outright. Keeping a copy of a finished trade
				// somewhere restorable is a way to receive the same Pokémon
				// twice, and no restore path should be able to hand a
				// cartridge the same result again. Only once retrieved_at is
				// set, so a game that deletes without downloading still gets
				// the trash's safety net.
				const gameMail = "(message like '%X-Game-code:%' and message like '%X-GBmail-type: exclusive%')";
				this._server.mysql.query(
					"delete from sys_inbox where id in (?) and deleted_at is null and retrieved_at is not null and " + gameMail,
					[deleteList],
					function (error) {
						if (error) { this._onError(error); return; }
						this._server.mysql.query("update sys_inbox set deleted_at = now(), deleted_by = 'game' where id in (?) and deleted_at is null", [deleteList], function (error2) {
							if (error2) {
								this._onError(error2);
							} else {
								this._send(true, "bye");
								this.close();
							}
						}.bind(this));
					}.bind(this)
				);
			} else {
				this._send(true, "bye");
				this.close();
			}
			break;
			
			case POP3State.UPDATE:
			this._send(true, "bye");
			this.close();
			break;
		}
	}
	
	_commandHandler_STAT(param) {
		if (this._state == POP3State.TRANSACTION) {
			this._send(true, this._maildrop.length + " " + (this._maildrop.length == 0 ? "0" : this._maildrop.map(entry => entry["size"]).reduce((a, b) => a + b, 0)));
		} else {
			this._send(false, "command not allowed");
		}
	}
	
	_commandHandler_LIST(param) {
		if (this._state == POP3State.TRANSACTION) {
			if (param != null && param != "" && !isNaN(param)) {
				if (this._maildrop[param - 1]) {
					//this._getMail(this._maildrop[param - 1]["id"], function(data) {
					this._send(true, param + " " + this._maildrop[param - 1]["size"] + "\r\n");
					//});
				} else {
					this._send(false, "no such message");
				}
			} else {
				//this._send(true, this._maildrop.length + " " + (this._maildrop.length == 0 ? "0" : this._maildrop.map(entry => entry["size"]).reduce((a, b) => a + b, 0)));
				this._send(false, "not implemented");
			}
		} else {
			this._send(false, "command not allowed");
		}
	}
	
	_commandHandler_RETR(param) {
		if (this._state == POP3State.TRANSACTION) {
			if (param != null && param != "" && !isNaN(param)) {
				if (this._maildrop[param - 1]) {
					const mailId = this._maildrop[param - 1]["id"];
					// Recorded on first retrieval only, so the trash can show
					// whether the game actually took a copy of the message or
					// discarded it unread -- once deleted the two look alike.
					this._server.mysql.query("update sys_inbox set retrieved_at = now() where id = ? and retrieved_at is null", [mailId], function (error) {
						if (error) this._onError(error);
					}.bind(this));
					this._getMail(mailId, function(data) {
						this._send(true, "message follows\r\n" + data + "\r\n.");
					});
				} else {
					this._send(false, "no such message");
				}
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
	}
	
	_commandHandler_TOP(param) {
		if (this._state == POP3State.TRANSACTION) {
			if (param != null) {
				let params = param.split(" ");
				if (params.length == 2 && !isNaN(params[0]) && !isNaN(params[1])) {
					let messageId = params[0] - 1;
					let lines = params[1];
					if (this._maildrop[messageId]) {
						this._getMail(this._maildrop[messageId]["id"], function(mailContent) {
							let end = mailContent.indexOf("\r\n\r\n") + 4;
							for (let i = 0; i < lines; i++) {
								if (mailContent.indexOf("\r\n", end) == -1) {
									end = mailContent.length;
								} else {
									end = mailContent.indexOf("\r\n", end) + 2;
								}
							}
							
							this._send(true, "top of message follows\r\n" + mailContent.substring(0, end) + "\r\n.");
						});
					} else {
						this._send(false, "no such message");
					}
				} else {
					this._send(false, "invalid parameter");
				}
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
	}
	
	_commandHandler_DELE(param) {
		if (this._state == POP3State.TRANSACTION) {
			if (param != null && param != "" && !isNaN(param)) {
				if (this._maildrop[param - 1]) {
					this._maildrop[param - 1]["deleted"] = true;
					this._send(true, "message deleted");
				} else {
					this._send(false, "no such message");
				}
			} else {
				this._send(false, "invalid parameter");
			}
		} else {
			this._send(false, "command not allowed");
		}
    }
	
	_commandHandler_RSET(param) {
		if (this._state == POP3State.TRANSACTION) {
			for (let i = 0; i < this._maildrop.length; i++) {
				this._maildrop[i]["deleted"] = false;
			}
			this._send(true, "reset successful");
		} else {
			this._send(false, "command not allowed");
		}
    }
	
	// Builds the maildrop, then enters TRANSACTION and acknowledges -- in that
	// order, and both inside the callback.
	//
	// Previously the state change and the "+OK" were emitted right after the
	// query was *issued*, so a client that sent STAT immediately got an empty
	// maildrop on a mailbox that had mail. It never showed up in practice
	// because the adapter pauses between commands, but the failure is silent
	// (an empty mailbox, not an error) and the window widens with database
	// latency, so it is not something to leave resting on client timing.
	//
	// Both authentication paths land here, XAPOP included -- which is the one
	// libmobile actually uses.
	_loadMaildrop(userId, okMessage) {
		// deleted_at is the trash marker. Without this filter the client would
		// re-download every trashed message on each sync and the mailbox would
		// never appear to empty.
		//
		// "order by id" is not decoration: this row order becomes the POP3
		// message numbering, and RETR/DELE address messages by that number.
		// Unordered, the numbering was whatever index the optimiser happened to
		// pick -- and three are candidates here, one of them (recipient,
		// read_at), which would have let reading mail in the webmail reshuffle
		// the numbers the game sees. Oldest first, by arrival, always.
		this._server.mysql.query(
			"select id, char_length(message) as size from sys_inbox where recipient = ? and deleted_at is null order by id",
			[userId],
			function (error, results, fields) {
				if (error) {
					this._onError(error);
					return;
				}
				for (let i = 0; i < results.length; i++) {
					this._maildrop[i] = [];
					this._maildrop[i]["id"] = results[i]["id"];
					this._maildrop[i]["size"] = results[i]["size"];
					this._maildrop[i]["deleted"] = false;
				}
				this._state = POP3State.TRANSACTION;
				this._send(true, okMessage);
			}.bind(this)
		);
	}

	_getMail(id, callback) {
		this._server.mysql.query("select message, sender, concat(substring(dayname(timestamp), 1, 3), ', ', day(timestamp), ' ', substring(monthname(timestamp), 1, 3), ' ', year(timestamp), ' ', time(timestamp), ' +0000')as timestamp from sys_inbox where id = ?", [id], function (error, results, fields) {
			// This callback runs on its own tick of the event loop, well
			// after _onData's try/catch around _onCommand() has already
			// returned -- nothing upstream can catch an exception thrown
			// here, so without this try/catch the connection just hangs
			// forever instead of getting a response (e.g. results[0] is
			// undefined if the row was deleted by another session between
			// PASS populating this._maildrop and this TOP/RETR call).
			try {
				if (error) {
					this._onError(error);
					return;
				}
				if (results.length === 0) {
					this._onError(new Error("message "+id+" vanished from sys_inbox mid-session"));
					return;
				}
				// Mail from our own domains was written for these games -- a
				// Trade Corner result, bottle mail, one player writing to
				// another -- and already carries exactly the headers they
				// parse, so nothing is taken away from it. Dropping headers
				// here is what stripped X-Game-result out of the Trade
				// Corner's mail and made Pokémon Crystal discard finished
				// trades. Foreign mail still gets slimmed: its Received/DKIM/
				// X-Proofpoint noise is no game's wire format, and every byte
				// of it is paid for over the serial link.
				let mailContent = this._isInternalSender(results[0]["sender"])
					? results[0]["message"].toString()
					: this._slimMessage(results[0]["message"]);

				// Added to both: no message stored here carries a Date of its
				// own, and the mailbox has a timestamp to offer.
				mailContent = this._withDate(mailContent, results[0]["timestamp"]);

				callback.call(this, mailContent);
			} catch (thrown) {
				this._onError(thrown);
			}
		}.bind(this));
	}

	// Puts the mailbox timestamp in as the last header. Adds nothing when the
	// message already states a Date -- two of them is not a date, it's an
	// ambiguity -- and leaves a message with no header/body separator alone
	// rather than splicing a header into the middle of its body.
	_withDate(mailContent, timestamp) {
		let sep = mailContent.indexOf("\r\n\r\n");
		if (sep === -1) return mailContent;
		if (/^Date:/im.test(mailContent.slice(0, sep))) return mailContent;
		return mailContent.slice(0, sep + 2) + "Date: " + timestamp + "\r\n" + mailContent.slice(sep + 2);
	}

	// Whether the message came from inside REON rather than off the internet.
	// Decided by the sender's domain, which is what deliver.js/smtp.js and the
	// Trade Corner job all write into sys_inbox.sender.
	_isInternalSender(sender) {
		let at = String(sender || "").lastIndexOf("@");
		if (at < 0) return false;
		let domain = String(sender).slice(at + 1).toLowerCase();
		return (this._server.internalDomains || []).indexOf(domain) >= 0;
	}

	// Real mail servers (Postfix included) attach Received/DKIM-Signature/
	// X-Google-*/References noise and, for anything sent from a normal mail
	// client, an HTML alternative part alongside the plain text one. None of
	// that exists in mail written game-to-game (smtp.js/deliver.js only ever
	// produced a handful of headers plus a single text/plain body), and a
	// 2001-era client reading over the emulated GB Link Cable's serial link
	// pays real seconds per byte for it. This only ever narrows the header
	// list and, for multipart messages, replaces the body with the
	// text/plain part's own (transfer-decoded) content — it never touches
	// bytes belonging to a single-part body, so the 7-bit-safe ISO-2022-JP
	// content native bottle mail already uses passes through untouched.
	_slimMessage(raw) {
		// sys_inbox.message is a BLOB column, so mysql2 hands this back as a
		// Buffer, not a string — Buffer#slice() returns another Buffer,
		// which has no .split(), so this must convert before any of the
		// string methods below run.
		raw = raw.toString();
		let sep = raw.indexOf("\r\n\r\n");
		if (sep === -1) return raw;
		let headers = this._parseHeaders(raw.slice(0, sep));
		let body = raw.slice(sep + 4);
		let contentType = headers["content-type"] ? headers["content-type"].value : "";

		let multipart = /^multipart\//i.test(contentType) && /boundary="?([^";]+)"?/i.exec(contentType);
		if (multipart) {
			let part = this._extractTextPlainPart(body, multipart[1]);
			if (part) {
				body = part.body;
				contentType = part.contentType;
			}
		}

		// The X-Game-* headers are not noise to strip: they are the mobile
		// protocol itself. Pokémon Crystal's Trade Corner reads the finished
		// trade out of X-Game-result and silently discards any message where
		// it is missing, which is exactly what dropping it here caused.
		const KEEP = [
			"mime-version", "from", "to", "subject",
			"x-game-title", "x-game-code", "x-game-result", "x-gbmail-type",
		];
		let lines = [];
		for (let key of KEEP) {
			if (!headers[key]) continue;
			let value = this._normalizeHeaderValue(headers[key].value);
			if (key === "subject") value = this._truncateSubject(value);
			lines.push(headers[key].name + ": " + value);
		}
		lines.push("Content-Type: " + (contentType || "text/plain; charset=us-ascii"));

		// A single-part body is forwarded byte for byte, so whatever encoded
		// it still describes it. The multipart branch above hands back the
		// part it kept already decoded, and re-announcing the old encoding
		// there would describe the body wrongly.
		if (!multipart && headers["content-transfer-encoding"]) {
			lines.push("Content-Transfer-Encoding: " + this._normalizeHeaderValue(headers["content-transfer-encoding"].value));
		}

		return lines.join("\r\n") + "\r\n\r\n" + body;
	}

	// Caps the Subject the game receives at SUBJECT_MAX_CHARS, ellipsis
	// included. Counted in characters rather than bytes: a JIS subject is
	// multi-byte, and cutting it by byte length would slice a character in
	// half and leave the ESC-sequence state dangling, which is exactly the
	// kind of malformed header a 2001-era parser has no defence against.
	//
	// A subject that already fits is returned byte-for-byte untouched -- the
	// re-encode path only ever runs on something that had to change anyway.
	_truncateSubject(value) {
		let text = this._decodeSubjectText(value);
		if (text === null) return value;

		// Array.from splits on codepoints, so characters outside the BMP
		// count as one rather than as two UTF-16 halves.
		let chars = Array.from(text);
		if (chars.length <= SUBJECT_MAX_CHARS) return value;

		let short = chars.slice(0, SUBJECT_MAX_CHARS - SUBJECT_ELLIPSIS.length).join("") + SUBJECT_ELLIPSIS;

		// Pure ASCII goes out plain; anything else has to go back into an
		// encoded-word, since a raw 8-bit header is not legal and the game
		// only renders ISO-2022-JP anyway.
		if (/^[\x20-\x7E]*$/.test(short)) return short;

		let jis = encoding.convert(encoding.stringToCode(short), { to: "JIS", from: "UNICODE" });
		return "=?ISO-2022-JP?B?" + Buffer.from(jis).toString("base64") + "?=";
	}

	// Returns the subject as plain text, or null if it can't be read with
	// confidence -- in which case the caller leaves the header alone rather
	// than risk emitting something worse than a long title.
	_decodeSubjectText(value) {
		if (!/=\?/.test(value)) return value;

		let out = "";
		let lastEnd = 0;
		let re = /=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g;
		let m;
		while ((m = re.exec(value)) !== null) {
			out += value.slice(lastEnd, m.index);
			lastEnd = m.index + m[0].length;

			let charset = m[1].toLowerCase();
			let bytes;
			if (m[2].toLowerCase() === "b") {
				bytes = Buffer.from(m[3], "base64");
			} else {
				// Q encoding: "_" is a space, "=XX" is a literal byte.
				bytes = Buffer.from(
					m[3].replace(/_/g, " ").replace(/=([0-9A-Fa-f]{2})/g, (_, h) => String.fromCharCode(parseInt(h, 16))),
					"latin1");
			}

			if (charset === "iso-2022-jp") {
				out += encoding.codeToString(encoding.convert(Array.from(bytes), { to: "UNICODE", from: "JIS" }));
			} else if (charset === "utf-8" || charset === "us-ascii") {
				out += bytes.toString("utf8");
			} else {
				return null;
			}
		}
		return out + value.slice(lastEnd);
	}

	// RFC 822 header parsing: unfolds continuation lines (they start with
	// whitespace), then splits each logical line on its first ":". Keyed by
	// lowercase name for lookup, but keeps the original name for output.
	_parseHeaders(rawHeaders) {
		let unfolded = [];
		for (let line of rawHeaders.split("\r\n")) {
			if (/^[ \t]/.test(line) && unfolded.length > 0) {
				unfolded[unfolded.length - 1] += line;
			} else {
				unfolded.push(line);
			}
		}
		let headers = {};
		for (let line of unfolded) {
			let idx = line.indexOf(":");
			if (idx === -1) continue;
			let name = line.slice(0, idx).trim();
			headers[name.toLowerCase()] = { name: name, value: line.slice(idx + 1).trim() };
		}
		return headers;
	}

	// Finds the first text/plain part of a multipart body and decodes its
	// Content-Transfer-Encoding (quoted-printable/base64 only carry 7-bit-
	// safe ASCII on the wire by spec, so reading them back with charCodeAt
	// recovers the original bytes exactly — no charset is touched here,
	// only the transfer envelope). Returns null on anything unexpected, so
	// _slimMessage's caller falls back to leaving the original body intact
	// rather than risk mangling it.
	_extractTextPlainPart(body, boundary) {
		let marker = "--" + boundary;
		let parts = body.split(marker).slice(1, -1);
		for (let part of parts) {
			part = part.replace(/^\r\n/, "");
			let sep = part.indexOf("\r\n\r\n");
			if (sep === -1) continue;
			let partHeaders = this._parseHeaders(part.slice(0, sep));
			let partBody = part.slice(sep + 4).replace(/\r\n$/, "");
			let partType = partHeaders["content-type"] ? partHeaders["content-type"].value : "text/plain";
			if (!/^text\/plain/i.test(partType)) continue;

			let encoding = partHeaders["content-transfer-encoding"] ? partHeaders["content-transfer-encoding"].value.toLowerCase() : "7bit";
			if (encoding === "quoted-printable") {
				partBody = this._decodeQuotedPrintable(partBody).toString("utf8");
			} else if (encoding === "base64") {
				partBody = Buffer.from(partBody.replace(/\s+/g, ""), "base64").toString("utf8");
			}

			// ISO-2022-JP is left completely alone: it's the one charset a
			// title like Mobile Trainer actually renders (via its own ESC
			// escape sequences into JIS X0208), and stripping to 0x20-0x7E
			// below would eat the ESC (0x1B) bytes those sequences depend
			// on. Anything else (Gmail's default is UTF-8) gets folded down
			// to plain ASCII — confirmed with the MAGB TestSuite ROM
			// project that the font has no latin-accent glyphs at all, so
			// bytes >=0x80 render as a wrong/random glyph, not the intended
			// character, and there's no safe destination to transcode to.
			let charsetMatch = /charset="?([^";]+)"?/i.exec(partType);
			let charset = charsetMatch ? charsetMatch[1].toLowerCase() : "us-ascii";
			if (charset !== "iso-2022-jp") {
				partBody = this._toAsciiSafe(partBody);
				partType = "text/plain; charset=us-ascii";
			}

			return { body: partBody, contentType: partType };
		}
		return null;
	}

	_decodeQuotedPrintable(text) {
		let bytes = [];
		for (let i = 0; i < text.length; i++) {
			if (text[i] === "=" && text[i + 1] === "\r" && text[i + 2] === "\n") {
				i += 2;
			} else if (text[i] === "=" && /^[0-9A-Fa-f]{2}$/.test(text.substr(i + 1, 2))) {
				bytes.push(parseInt(text.substr(i + 1, 2), 16));
				i += 2;
			} else {
				bytes.push(text.charCodeAt(i));
			}
		}
		return Buffer.from(bytes);
	}

	// Folds accented Latin characters to their bare ASCII form (NFD
	// decomposition + strip combining marks, e.g. "á" -> "a"+"´" -> "a") and
	// replaces anything else outside printable ASCII with "?", since the
	// font has no glyph for it at all — confirmed against the actual ROM,
	// not just the spec. Backslash and backtick are pulled out separately:
	// the game's own 4-page input keyboard (checked against real
	// screenshots) covers every other printable ASCII symbol, but neither
	// of these two appears on any page -- no confirmed glyph, and fonts of
	// this era/origin are known to render 0x5C ("\\") as the yen sign
	// instead of a backslash, so it's not safe to assume it displays as typed.
	_toAsciiSafe(text) {
		return text
			.normalize("NFD")
			.replace(/[\u0300-\u036f]/g, "")
			.replace(/[\\`]/g, "?")
			.replace(/[^\x20-\x7E\r\n]/g, "?");
	}

	// Decodes RFC 2047 encoded-words ("=?charset?B|Q?text?=", as used in
	// From/To/Subject for anything outside plain ASCII) and applies the same
	// accent-folding as the body. ISO-2022-JP encoded-words are left
	// completely alone -- that's the one encoding the game itself already
	// decodes (see the native message header example this was built from),
	// so re-touching it would only risk breaking what already works.
	// Adjacent encoded-words separated only by whitespace are RFC 2047
	// "folded" together (the whitespace between them is not part of the
	// content), so that gap is dropped rather than kept literally.
	_normalizeHeaderValue(value) {
		let result = "";
		let lastIndex = 0;
		let lastWasEncoded = false;
		let re = /=\?([^?]+)\?([BbQq])\?([^?]*)\?=/g;
		let match;
		while ((match = re.exec(value)) !== null) {
			let between = value.slice(lastIndex, match.index);
			if (!(lastWasEncoded && /^[ \t]+$/.test(between))) {
				result += this._toAsciiSafe(between);
			}
			let charset = match[1];
			let encoding = match[2].toUpperCase();
			let text = match[3];
			if (charset.toLowerCase().startsWith("iso-2022-jp")) {
				result += match[0];
			} else {
				let decodedBytes = encoding === "B"
					? Buffer.from(text, "base64")
					: this._decodeQuotedPrintable(text.replace(/_/g, " "));
				result += this._toAsciiSafe(decodedBytes.toString("utf8"));
			}
			lastIndex = re.lastIndex;
			lastWasEncoded = true;
		}
		result += this._toAsciiSafe(value.slice(lastIndex));
		return result;
	}
}
module.exports.POP3Connection = POP3Connection;
