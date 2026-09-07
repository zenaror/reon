<?php
	require_once("DBUtil.php");
	require_once("ConfigUtil.php");

	// Web client's view over sys_inbox.
	//
	// Deletion here and over POP3 is a move to the trash (deleted_at), not a
	// removal: the Mobile Trainer has no "leave on server" mode and can delete
	// a message without ever downloading it, so a hard delete destroyed mail
	// that nothing had read. The trash is only reachable from the web, and a
	// purge job clears it after the retention window.
	class MailUtil {

		// Days a message survives in the trash before the purge job removes it.
		const TRASH_RETENTION_DAYS = 30;

		// What a Mobile Trainer message can hold: 8 lines of 12 characters.
		// The Trainer wraps long lines itself, so the per-line width is not
		// enforced here -- only the totals, which are what it cannot exceed.
		// Line breaks are not counted against the character budget: 96 is the
		// text capacity (8 x 12), not the size of the stored message.
		const BODY_MAX_LINES = 8;
		const BODY_MAX_CHARS = 96;

		// Returns an error code, or null when the body fits.
		public function checkBodyFits($body) {
			$normalized = preg_replace('/\r\n|\r/', "\n", (string)$body);
			$lines = explode("\n", $normalized);

			if (count($lines) > self::BODY_MAX_LINES) return "too-many-lines";
			if (mb_strlen(str_replace("\n", "", $normalized)) > self::BODY_MAX_CHARS) return "too-many-chars";
			return null;
		}

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new MailUtil();
			}
			return self::$instance;
		}

		public function listForUser($userId, $folder = "inbox") {
			$db = DBUtil::getInstance()->getDB();
			$where = $folder === "trash" ? "deleted_at is not null" : "deleted_at is null";
			$stmt = $db->prepare("
				select id, sender, timestamp, message, deleted_at, deleted_by, retrieved_at
				from sys_inbox
				where recipient = ? and $where
				order by timestamp desc, id desc
			");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$result = $stmt->get_result();

			$out = [];
			while ($row = $result->fetch_assoc()) {
				$parsed = $this->parse($row["message"]);
				$out[] = [
					"id" => $row["id"],
					"sender" => $row["sender"],
					"timestamp" => $row["timestamp"],
					"subject" => $parsed["subject"],
					"from_name" => $parsed["from_name"],
					"game" => $parsed["game"],
					"deleted_at" => $row["deleted_at"],
					"deleted_by" => $row["deleted_by"],
					// Distinguishes mail the game took a copy of from mail it
					// discarded unread; only meaningful for trashed messages.
					"retrieved" => $row["retrieved_at"] !== null,
				];
			}
			return $out;
		}

		// Moving to the trash hides the message from POP3, so the game stops
		// offering it, but nothing is destroyed until the purge runs.
		public function moveToTrash($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				update sys_inbox set deleted_at = now(), deleted_by = 'web'
				where id = ? and recipient = ? and deleted_at is null
			");
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			return $stmt->affected_rows > 0;
		}

		// Resolves an address to a REON account id, or null if it belongs to
		// the real internet. Matches on either form of the local part -- the
		// full username or the 8-character one the games are limited to --
		// exactly as mail/deliver.js does for inbound mail, and regardless of
		// which of our domains it was addressed to.
		public function resolveLocalRecipient($address) {
			$local = trim((string)$address);
			$at = strpos($local, "@");
			$domain = "";
			if ($at !== false) {
				$domain = strtolower(substr($local, $at + 1));
				$local = substr($local, 0, $at);
			}

			$cfg = ConfigUtil::getInstance()->getConfig();
			$ours = [strtolower($cfg["email_domain_dion"] ?? ""), strtolower($cfg["email_domain"] ?? "")];
			// A bare local part with no domain is treated as one of ours;
			// anything addressed to someone else's domain never is.
			if ($domain !== "" && !in_array($domain, $ours, true)) return null;
			if ($local === "") return null;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select id from sys_users where username = ? or dion_email_local = ? limit 1");
			$stmt->bind_param("ss", $local, $local);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			return $row ? (int)$row["id"] : null;
		}

		// Builds the message in the shape pop3Connection.js expects to hand to
		// a Game Boy: CRLF throughout, a blank CRLF line between headers and
		// body, and a charset the adapter can actually render.
		//
		// Latin text goes out as us-ascii. Anything else is converted to
		// ISO-2022-JP, the one charset the Mobile Trainer renders -- sending
		// UTF-8 would reach the webmail intact but show as garbage in-game.
		private function buildMessage($fromAddress, $fromName, $toAddress, $subject, $body) {
			$isAscii = function ($text) {
				return preg_match('/^[\x00-\x7F]*$/', (string)$text) === 1;
			};
			$toJis = function ($text) {
				$jis = @mb_convert_encoding((string)$text, "ISO-2022-JP", "UTF-8");
				return $jis === false ? (string)$text : $jis;
			};
			// CR and LF are stripped from every header value before it is
			// used. Without this a newline in the subject or the address
			// ends the header and whatever follows becomes a real one -- a
			// "Bcc:" typed into the subject box would be honoured, which on
			// the outbound path is a spam relay.
			$headerSafe = function ($text) {
				return trim(preg_replace('/[\r\n]+/', " ", (string)$text));
			};
			$encodeHeader = function ($text) use ($isAscii, $toJis, $headerSafe) {
				$text = $headerSafe($text);
				if ($isAscii($text)) return $text;
				return "=?ISO-2022-JP?B?" . base64_encode($toJis($text)) . "?=";
			};

			$bodyIsAscii = $isAscii($body);
			$charset = $bodyIsAscii ? "us-ascii" : "iso-2022-jp";
			$wireBody = $bodyIsAscii ? (string)$body : $toJis($body);

			$headers = [
				"MIME-Version: 1.0",
				"From: " . $headerSafe($fromAddress) . ($fromName !== "" ? " (" . $encodeHeader($fromName) . ")" : ""),
				"To: " . $headerSafe($toAddress),
				"Subject: " . $encodeHeader($subject),
				"Content-Type: text/plain; charset=" . $charset,
			];

			$message = implode("\r\n", $headers) . "\r\n\r\n" . $wireBody;
			// Normalize whatever the browser submitted to CRLF, the way
			// deliver.js does for what Postfix hands it.
			return preg_replace('/\r\n|\r|\n/', "\r\n", $message);
		}

		// Sends from $fromUserId. Returns [ok, reason]; "external" means the
		// recipient is off-site and this path cannot deliver it yet.
		public function send($fromUserId, $toAddress, $subject, $body) {
			$db = DBUtil::getInstance()->getDB();
			$fromUserId = (int)$fromUserId;

			// Checked before anything else, and for every destination: a
			// message that cannot be displayed on a Game Boy is refused
			// outright rather than sent and silently truncated later.
			$tooLong = $this->checkBodyFits($body);
			if ($tooLong !== null) return [false, $tooLong];

			$stmt = $db->prepare("select username, dion_email_local from sys_users where id = ? limit 1");
			$stmt->bind_param("i", $fromUserId);
			$stmt->execute();
			$sender = $stmt->get_result()->fetch_assoc();
			if (!$sender) return [false, "no-sender"];

			$recipientId = $this->resolveLocalRecipient($toAddress);
			if ($recipientId === null) {
				return $this->sendExternal($fromUserId, $sender, $toAddress, $subject, $body);
			}

			$cfg = ConfigUtil::getInstance()->getConfig();
			// Sent from the DION address so a reply from inside a game lands
			// back here: that is the address the adapter knows how to answer.
			$fromAddress = $sender["dion_email_local"] . "@" . $cfg["email_domain_dion"];

			$message = $this->buildMessage(
				$fromAddress, (string)$sender["username"], trim((string)$toAddress),
				trim((string)$subject), (string)$body
			);

			$stmt = $db->prepare("insert into sys_inbox (sender, recipient, message) values (?, ?, ?)");
			$stmt->bind_param("sis", $fromAddress, $recipientId, $message);
			$stmt->execute();
			return [$stmt->affected_rows > 0, $stmt->affected_rows > 0 ? "sent" : "insert-failed"];
		}

		// Outbound sends allowed per account per hour.
		const OUTBOUND_PER_HOUR = 20;

		// Sends to a real internet address.
		//
		// This does not go through smtpd, so it never meets the device-auth
		// policy that gates the game's relay (see mail/relayPolicy.js) -- that
		// gate stays exactly as strict as it was. Local submission is a
		// separate path whose authorization is the web session: the caller is
		// logged in, and the From address is taken from their account rather
		// than from the form, so nobody can send as anyone else.
		//
		// Postfix routes it to default_transport = reonoutbound, which is
		// mail/outboundRelay.js -- the same relay, domain rewriting included,
		// that game mail already uses.
		private function sendExternal($fromUserId, $sender, $toAddress, $subject, $body) {
			$toAddress = trim((string)$toAddress);
			if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
				return [false, "bad-address"];
			}
			if ($this->outboundCountLastHour($fromUserId) >= self::OUTBOUND_PER_HOUR) {
				return [false, "rate-limited"];
			}

			$cfg = ConfigUtil::getInstance()->getConfig();
			// The externally routable form of their address, so a reply comes
			// back to them rather than to a domain the internet cannot answer.
			$fromAddress = $sender["username"] . "@" . $cfg["email_domain"];

			$message = $this->buildMessage(
				$fromAddress, (string)$sender["username"], $toAddress,
				trim((string)$subject), (string)$body
			);

			$accepted = $this->submitLocally($fromAddress, $toAddress, $message);
			$this->logOutbound($fromUserId, $toAddress, $subject, $accepted);
			return [$accepted, $accepted ? "sent" : "relay-failed"];
		}

		// Handed to sendmail as an argument list, never as a shell string, so
		// an address cannot become part of a command.
		private function submitLocally($envelopeFrom, $recipient, $message) {
			$cmd = ["/usr/sbin/sendmail", "-i", "-f", $envelopeFrom, "--", $recipient];
			$spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return false;

			fwrite($pipes[0], $message);
			fclose($pipes[0]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($proc);

			if ($status !== 0) {
				error_log("MailUtil: sendmail exited {$status}: " . trim((string)$err));
			}
			return $status === 0;
		}

		private function outboundCountLastHour($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select count(*) as c from sys_web_outbound_log
				 where user_id = ? and created_at > date_sub(now(), interval 1 hour)"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Recorded whether or not the relay accepted it: a burst of failures
		// is exactly the pattern worth being able to see afterwards.
		private function logOutbound($userId, $recipient, $subject, $accepted) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_web_outbound_log (user_id, recipient, subject, accepted) values (?, ?, ?, ?)"
			);
			$userId = (int)$userId;
			$recipient = substr((string)$recipient, 0, 254);
			$subject = substr((string)$subject, 0, 255);
			$acceptedInt = $accepted ? 1 : 0;
			$stmt->bind_param("issi", $userId, $recipient, $subject, $acceptedInt);
			$stmt->execute();
		}

		// Bulk variants of the three actions. Each runs as one statement rather
		// than a loop, so a selection either applies whole or not at all, and
		// each carries the same recipient scoping and same-folder guard as its
		// single-message counterpart -- ids belonging to someone else, or in
		// the wrong folder, simply match nothing.
		//
		// $ids is cast to int per element and the placeholder list is built
		// from the count, so nothing from the request reaches the SQL text.
		private function bulk($userId, $ids, $sqlHead, $guard) {
			$ids = array_values(array_filter(array_map("intval", (array)$ids)));
			if (empty($ids)) return 0;

			$db = DBUtil::getInstance()->getDB();
			$placeholders = implode(",", array_fill(0, count($ids), "?"));
			$stmt = $db->prepare("$sqlHead where recipient = ? and $guard and id in ($placeholders)");

			$params = array_merge([(int)$userId], $ids);
			$stmt->bind_param(str_repeat("i", count($params)), ...$params);
			$stmt->execute();
			return $stmt->affected_rows;
		}

		public function moveToTrashMany($userId, $ids) {
			return $this->bulk($userId, $ids,
				"update sys_inbox set deleted_at = now(), deleted_by = 'web'", "deleted_at is null");
		}

		public function restoreMany($userId, $ids) {
			return $this->bulk($userId, $ids,
				"update sys_inbox set deleted_at = null, deleted_by = null", "deleted_at is not null");
		}

		public function deleteForeverMany($userId, $ids) {
			return $this->bulk($userId, $ids,
				"delete from sys_inbox", "deleted_at is not null");
		}

		// Restoring puts the message back in POP3's maildrop, so the game will
		// download it again on the next sync -- which is the point.
		public function restore($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				update sys_inbox set deleted_at = null, deleted_by = null
				where id = ? and recipient = ? and deleted_at is not null
			");
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			return $stmt->affected_rows > 0;
		}

		// Only ever applies to something already in the trash, so a single
		// mistaken click can never destroy a message outright.
		public function deleteForever($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("delete from sys_inbox where id = ? and recipient = ? and deleted_at is not null");
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			return $stmt->affected_rows > 0;
		}

		// Messages that arrived since this account last opened the webmail
		// inbox, counted by id rather than by date -- see the migration for
		// why. A marker of 0 means it never has, so everything counts.
		public function countNewForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select count(*) as c from sys_inbox i
				 join sys_users u on u.id = i.recipient
				 where i.recipient = ? and i.deleted_at is null
				   and i.id > u.mail_seen_id"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Called when the inbox list is shown, and only then: opening a single
		// message or the trash must not clear the marker for mail the person
		// has not actually looked at yet.
		//
		// greatest() so the marker only ever moves forward. Without it,
		// opening an inbox whose newest message was since trashed would move
		// it backwards and resurrect older mail as "new".
		public function markInboxSeen($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"update sys_users u
				 set u.mail_seen_id = greatest(
				     u.mail_seen_id,
				     coalesce((select max(i.id) from sys_inbox i where i.recipient = u.id), 0)
				 )
				 where u.id = ?"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
		}

		public function countTrashForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_inbox where recipient = ? and deleted_at is not null");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Scoped by recipient as well as id: the message id alone must never be
		// enough to read someone else's mail.
		public function getForUser($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			// Deliberately not filtered on deleted_at: a trashed message still
			// has to be readable, that being the point of keeping it.
			$stmt = $db->prepare("
				select id, sender, timestamp, message, deleted_at from sys_inbox
				where id = ? and recipient = ? limit 1
			");
			$id = (int)$id;
			$userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (!$row) return null;

			$parsed = $this->parse($row["message"]);
			return [
				"id" => $row["id"],
				"sender" => $row["sender"],
				"timestamp" => $row["timestamp"],
				"subject" => $parsed["subject"],
				"from_name" => $parsed["from_name"],
				"game" => $parsed["game"],
				"body" => $parsed["body"],
				"deleted_at" => $row["deleted_at"],
			];
		}

		public function countForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_inbox where recipient = ? and deleted_at is null");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Mail written on a Game Boy arrives as JIS: headers as MIME
		// encoded-words and the body raw ISO-2022-JP, so both need converting
		// before anything can be shown in a browser.
		private function parse($raw) {
			$raw = (string)$raw;
			$split = preg_split("/\r?\n\r?\n/", $raw, 2);
			$headerText = $split[0];
			$body = isset($split[1]) ? $split[1] : "";

			$headers = [];
			// Unfold continuation lines before splitting on ":".
			$headerText = preg_replace("/\r?\n[ \t]+/", " ", $headerText);
			foreach (preg_split("/\r?\n/", $headerText) as $line) {
				$pos = strpos($line, ":");
				if ($pos === false) continue;
				$headers[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
			}

			$charset = "ISO-2022-JP";
			if (isset($headers["content-type"]) &&
			    preg_match('/charset\s*=\s*"?([A-Za-z0-9_-]+)"?/i', $headers["content-type"], $m)) {
				$charset = $m[1];
			}

			return [
				"subject" => $this->decodeHeader($headers["subject"] ?? ""),
				// The display name sits in parentheses after the address.
				"from_name" => preg_match('/\(([^)]*)\)/', $headers["from"] ?? "", $m)
					? $this->decodeHeader($m[1]) : "",
				"game" => $headers["x-game-title"] ?? "",
				"body" => $this->decodeBody($body, $charset),
			];
		}

		// A header may mix encoded-words with literal text. Handing the whole
		// string to mb_decode_mimeheader() replaces every non-ASCII byte in the
		// literal parts with "?", which destroys the raw UTF-8 that external
		// senders routinely put there, so only the encoded-words are decoded.
		private function decodeHeader($value) {
			if ($value === "") return "";

			// Whitespace separating two adjacent encoded-words is folding, not
			// text (RFC 2047 s6.2), so it goes before the words are decoded.
			$value = preg_replace('/\?=\s+=\?/', "?==?", $value);

			$decoded = preg_replace_callback(
				'/=\?[^?]+\?[BbQq]\?[^?]*\?=/',
				function ($m) {
					$word = @mb_decode_mimeheader($m[0]);
					return ($word === false || $word === "") ? $m[0] : $word;
				},
				$value
			);
			if ($decoded === null) $decoded = $value;

			// Literal bytes were passed through untouched above, so a sender
			// using an 8-bit charset instead of UTF-8 would leave the string
			// invalid; Twig would then escape it to nothing.
			return mb_check_encoding($decoded, "UTF-8")
				? $decoded
				: mb_convert_encoding($decoded, "UTF-8", "ISO-8859-1");
		}

		private function decodeBody($body, $charset) {
			if ($body === "") return "";
			// Unknown or already-UTF-8 charsets are passed through rather than
			// mangled by a conversion that guesses wrong.
			if (strtoupper($charset) === "UTF-8") return $body;
			$converted = @mb_convert_encoding($body, "UTF-8", $charset);
			return ($converted === false || $converted === "") ? $body : $converted;
		}
	}
?>
