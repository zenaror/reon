// LEGACY: REON's own POP3 server. Off since 2026-09-12 (config.json `disable_pop3`):
// Dovecot answers port 110 now (examples/dovecot/). Kept for reference and for
// the development environments that still run it; do not enable it next to Dovecot.
const EventEmitter = require("events");
const crypto = require("crypto");
const gameFormat = require("./gameFormat.js");
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

// RFC 1939 multiline replies end with CRLF dot CRLF. If the message already ends with
// CRLF, adding another would insert a spurious blank line and alter binary trade payloads.
function terminaEmCRLF(texto) {
	return texto.endsWith("\r\n") ? texto : texto + "\r\n";
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
		// The greeting presents the same nonce in the historical service-ready hex form and the
		// RFC 1939 angle-bracket challenge. The latter includes < and > in the APOP digest and
		// matches the Dovecot greeting format; preserve compatibility with existing clients.
		this._challenge = "<" + this._nonce + "@reon.dion.ne.jp>";
		this._send(true, "service ready " + this._nonce + "@reon.dion.ne.jp "
			+ this._challenge);
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
					// The admin-controlled password fallback determines whether the eight-character game
					// password is accepted. Disabling it leaves the supported key-based authentication
					// methods; rollout policy belongs to the operator.
					this._server.mysql.query(
						"select u.id, u.log_in_password, " +
						"  coalesce((select s.value from sys_settings s where s.name = 'pop3_password_fallback'), '1') as fallback " +
						"from sys_users u where u.dion_email_local = ? and u.banned_at is null limit 1",
						[this._user], function (error, results, fields) {
						if (error) {
							this._onError(error);
						} else {
							if (results.length > 0 && results[0]["fallback"] !== "1") {
								// Distinguish disabled password authentication from an invalid credential so operators do
								// not diagnose the policy switch as a wrong password.
								this._send(false, "password authentication disabled");
							} else if (results.length > 0) {
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
	
	// XAPOP authenticates a provisioned device using HMAC-SHA256(xapopSubkey(device_auth_key),
	// ppp_id + "|" + greeting nonce), replacing USER/PASS. Failure leaves the connection in
	// AUTHORIZATION so fallback remains possible when policy permits. RFC 1939 APOP instead
	// uses MD5(challenge + secret). The secret is the device key encoded as 64 lowercase ASCII
	// hex characters, not its raw 32 bytes, matching Dovecot. The adapter computes this
	// digest; the cartridge password is not used.
	_commandHandler_APOP(param) {
		if (this._state != POP3State.AUTHORIZATION) {
			this._send(false, "command not allowed");
			return;
		}
		let params = param != null ? param.split(" ") : [];
		if (params.length != 2 || !/^[0-9a-f]{32}$/i.test(params[1])) {
			this._send(false, "invalid parameter");
			return;
		}
		let user = params[0];
		let digest = Buffer.from(params[1].toLowerCase(), "hex");

		this._server.mysql.query(
			// Refuse banned accounts like unknown accounts on this authentication path too.
			"select u.id, u.dion_email_local, a.device_auth_key from sys_users u " +
			"inner join sys_device_authorization a on a.user_id = u.id " +
			"where u.dion_email_local = ? and u.banned_at is null",
			[user],
			function (error, results, fields) {
				if (error) {
					this._onError(error);
					return;
				}
				if (results.length === 0) {
					this._send(false, "invalid user or pass");
					return;
				}
				let segredo = results[0]["device_auth_key"].toString("hex");
				let esperado = crypto.createHash("md5")
					.update(this._challenge + segredo, "binary").digest();
				if (!crypto.timingSafeEqual(esperado, digest)) {
					this._send(false, "invalid user or pass");
					return;
				}
				this._userId = results[0]["id"];
				this._user = results[0]["dion_email_local"];
				this._loadMaildrop(this._userId, "pass accepted");
			}.bind(this)
		);
	}

	_commandHandler_XAPOP(param) {
		if (this._state == POP3State.AUTHORIZATION) {
			let params = param != null ? param.split(" ") : [];
			if (params.length == 2 && /^g[0-9]{9}$/.test(params[0]) && /^[0-9a-f]{64}$/.test(params[1])) {
				let pppId = params[0];
				let sig = Buffer.from(params[1], "hex");
				this._server.mysql.query(
					// A banned account is refused like an unknown account. Also fetch dion_email_local:
					// key-based authentication supplies no prior USER command, and Dovecot needs the mailbox
					// name as well as the numeric account ID.
					"select u.id, u.dion_email_local, a.device_auth_key from sys_users u inner join sys_device_authorization a on a.user_id = u.id where u.dion_ppp_id = ? and u.banned_at is null",
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
						this._user = results[0]["dion_email_local"];
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
				this._server.store.remove(this._storeCtx(), deleteList).then(function () {
					this._send(true, "bye");
					this.close();
				}.bind(this)).catch(function (error) { this._onError(error); }.bind(this));
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
					this._server.store.markRetrieved(this._storeCtx(), mailId)
						.catch(function (error) { this._onError(error); }.bind(this));
					this._getMail(mailId, function(data) {
						this._send(true, "message follows\r\n" + terminaEmCRLF(data) + ".");
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
							
							this._send(true, "top of message follows\r\n" + terminaEmCRLF(mailContent.substring(0, end)) + ".");
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
		this._server.store.list(this._storeCtx()).then(function (results) {
			for (let i = 0; i < results.length; i++) {
				this._maildrop[i] = [];
				this._maildrop[i]["id"] = results[i]["id"];
				this._maildrop[i]["size"] = results[i]["size"];
				this._maildrop[i]["deleted"] = false;
			}
			this._state = POP3State.TRANSACTION;
			this._send(true, okMessage);
		}.bind(this)).catch(function (error) { this._onError(error); }.bind(this));
	}

	_getMail(id, callback) {
		// This callback runs in a later event-loop turn, beyond _onData try/catch. Catch errors
		// here so the connection replies instead of remaining silent indefinitely.
		this._server.store.fetch(this._storeCtx(), id).then(function (row) {
			const results = [row];
			try {
				// If the delivery filter already shaped the message, serve the stored bytes without
				// shaping again. Reclassification from Return-Path after the delivery filter removed that
				// header would treat internal game mail as external and corrupt it. This also matches
				// Dovecot behavior.
				if (this._server.shapedAtDelivery) {
					callback.call(this, results[0]["message"].toString("binary"));
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
					? this._stripTransportHeaders(results[0]["message"])
					: this._slimMessage(results[0]["message"]);

				// Add a Date only when missing. Directly inserted mail lacked one, while Postfix envelope
				// removal may strip it; the mailbox timestamp is available in both cases.
				mailContent = this._withDate(mailContent, results[0]["timestamp"]);

				callback.call(this, mailContent);
			} catch (thrown) {
				this._onError(thrown);
			}
		}.bind(this)).catch(function (error) {
			// The message may disappear between PASS building the maildrop and RETR, due to another
			// session or device. Reply with an error rather than leaving the connection waiting.
			this._onError(error);
		}.bind(this));
	}

	// Storage context requires both the account ID used by MySQL and the mailbox name used by
	// Dovecot. Populate both during each supported authentication path.
	_storeCtx() {
		return { id: this._userId, name: this._user };
	}

	// Delegate transformations to the shared gameFormat.js module so delivery filtering and
	// the optional POP3 reader use the same implementation.
	_decodeQuotedPrintable(...args) { return gameFormat.decodeQuotedPrintable(...args); }
	_decodeSubjectText(...args) { return gameFormat.decodeSubjectText(...args); }
	_extractTextPlainPart(...args) { return gameFormat.extractTextPlainPart(...args); }
	_isInternalSender(sender) {
		return gameFormat.isInternalSender(sender, this._server.internalDomains);
	}
	_normalizeHeaderValue(...args) { return gameFormat.normalizeHeaderValue(...args); }
	_parseHeaders(...args) { return gameFormat.parseHeaders(...args); }
	_rfcDate(...args) { return gameFormat.rfcDate(...args); }
	_slimMessage(...args) { return gameFormat.slimMessage(...args); }
	_stripTransportHeaders(...args) { return gameFormat.stripTransportHeaders(...args); }
	_toAsciiSafe(...args) { return gameFormat.toAsciiSafe(...args); }
	_truncateSubject(...args) { return gameFormat.truncateSubject(...args); }
	_withDate(...args) { return gameFormat.withDate(...args); }
}
module.exports.POP3Connection = POP3Connection;
