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
		// Line breaks are not counted against the character budget: 96 is the
		// text capacity (8 x 12), not the size of the stored message.
		//
		// The two totals are not independent limits. A single 96-character
		// line is one line and 96 characters -- inside both totals -- yet it
		// occupies all 8 rows once it is broken to the 12-column width, and
		// anything after it falls off the screen. So the line count that
		// matters is the one *after* wrapping, which is what checkBodyFits
		// measures.
		const BODY_MAX_LINES = 8;
		const BODY_MAX_CHARS = 96;
		const BODY_MAX_LINE_CHARS = 12;

		// What the game's mailbox shows of a title. A game never writes a
		// longer one, so the only way an over-long title reaches a player is
		// the webmail -- which makes this a rule about what may be composed
		// here, checked at that moment, rather than something to trim off a
		// message on its way out. Delivery hands a player's mail to the game
		// exactly as it was stored.
		const SUBJECT_MAX_CHARS = 10;

		// How many rows a folder shows at once, and what the reader may pick
		// instead. Paging happens in PHP on the already-filtered list rather
		// than in SQL: the inbox lists conversations, which only exist after
		// the rows are read and grouped, so there is no query to LIMIT.
		const PAGE_SIZES = [10, 15, 25, 50, 100];
		const PAGE_SIZE_DEFAULT = 15;

		// The local part our own services send from. Nobody can register it as
		// a username, so an address at one of our domains under this name is
		// always machine traffic.
		const SERVICE_LOCAL_PART = "system";

		// The domains a recipient can be "one of ours" under: the game's DION
		// domain and the site's real-internet mail domain, lower-cased. The
		// compose page uses them to decide when to show the Game Boy limits.
		public function internalDomains() {
			$cfg = ConfigUtil::getInstance()->getConfig();
			return array_values(array_filter([
				strtolower($cfg["email_domain_dion"] ?? ""),
				strtolower($cfg["email_domain"] ?? ""),
			]));
		}

		// Breaks a single line to the screen width, at spaces where possible.
		// A word with no space to break at (a long URL, a keysmash) is cut at
		// the column, which is what the screen does to it anyway.
		private function wrapOneLine($line) {
			$width = self::BODY_MAX_LINE_CHARS;
			$out = [];
			$current = "";

			foreach (explode(" ", $line) as $word) {
				while (mb_strlen($word) > $width) {
					if ($current !== "") { $out[] = $current; $current = ""; }
					$out[] = mb_substr($word, 0, $width);
					$word = mb_substr($word, $width);
				}
				if ($current === "") {
					$current = $word;
				} elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
					$current .= " ".$word;
				} else {
					$out[] = $current;
					$current = $word;
				}
			}

			$out[] = $current;
			return $out;
		}

		// The body as the game will actually lay it out. The compose page runs
		// the same wrap in JavaScript so what is typed is what is stored.
		public function wrapBody($body) {
			$normalized = preg_replace('/\r\n|\r/', "\n", (string)$body);
			$out = [];
			foreach (explode("\n", $normalized) as $line) {
				foreach ($this->wrapOneLine($line) as $piece) $out[] = $piece;
			}
			return implode("\n", $out);
		}

		// One page of an already-filtered list, plus what the controls need to
		// describe it. The page number is clamped rather than trusted: a
		// bookmarked page 9, a narrowed filter, or deleting the last page's
		// only row should land on the last real page, never on an empty one.
		public function paginate($rows, $page, $per) {
			$per = (int)$per > 0 ? (int)$per : self::PAGE_SIZE_DEFAULT;
			$total = count($rows);
			$pages = max(1, (int)ceil($total / $per));
			$page = max(1, min((int)$page, $pages));
			$offset = ($page - 1) * $per;

			return [array_slice($rows, $offset, $per), [
				"page" => $page,
				"pages" => $pages,
				"per" => $per,
				"total" => $total,
				"offset" => $offset,
				"sizes" => self::PAGE_SIZES,
			]];
		}

		// The addresses our own services write from.
		public function serviceSenders() {
			$out = [];
			foreach ($this->internalDomains() as $domain) {
				// Config, not user input, but kept to the shape a domain can
				// legally take -- these end up inside a SQL literal below.
				if (preg_match('/^[a-z0-9.-]+$/', $domain)) {
					$out[] = self::SERVICE_LOCAL_PART."@".$domain;
				}
			}
			return $out;
		}

		public function isServiceSender($sender) {
			return in_array(strtolower(trim((string)$sender)), $this->serviceSenders(), true);
		}

		// A message is a game's own traffic when its headers say so -- the pair
		// the Mobile Trainer itself tests -- or when it came from one of our
		// service addresses. Both tests, because they cover different holes: a
		// service could send something those headers do not describe, and a
		// game can post mail from a player's own address (bottle mail does),
		// which is a letter between people and must stay in the inbox.
		public function isGameMail($parsed, $sender) {
			return !empty($parsed["is_game"]) || $this->isServiceSender($sender);
		}

		// The same rule in SQL: the counters run over the whole mailbox on
		// every page render, so they must not read and parse every blob.
		private function gameMailSql() {
			$sql = "(message like '%X-Game-code:%' and message like '%X-GBmail-type: exclusive%')";
			$senders = $this->serviceSenders();
			if (!empty($senders)) {
				$list = implode(",", array_map(function ($a) { return "'".$a."'"; }, $senders));
				$sql = "($sql or lower(sender) in ($list))";
			}
			return $sql;
		}

		// Returns an error code, or null when the subject fits.
		public function checkSubjectFits($subject) {
			if (mb_strlen(trim((string)$subject)) > self::SUBJECT_MAX_CHARS) return "subject-too-long";
			return null;
		}

		// Returns an error code, or null when the body fits.
		public function checkBodyFits($body) {
			$wrapped = $this->wrapBody($body);
			$lines = explode("\n", $wrapped);

			if (count($lines) > self::BODY_MAX_LINES) return "too-many-lines";
			if (mb_strlen(str_replace("\n", "", $wrapped)) > self::BODY_MAX_CHARS) return "too-many-chars";
			return null;
		}

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new MailUtil();
			}
			return self::$instance;
		}

		// Folders over one table: "inbox" and "trash" are the human ones and
		// never show a game's own mail; "game" is the read-only window onto
		// exactly that mail, whether it is still waiting or the game has
		// already taken and deleted it.
		public function listForUser($userId, $folder = "inbox") {
			$db = DBUtil::getInstance()->getDB();
			$where = $folder === "trash" ? "deleted_at is not null"
				: ($folder === "game" ? "1" : "deleted_at is null");
			$stmt = $db->prepare("
				select id, sender, timestamp, message, deleted_at, deleted_by, retrieved_at, read_at
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
				$isGame = $this->isGameMail($parsed, $row["sender"]);
				if ($folder === "game" ? !$isGame : $isGame) continue;
				$out[] = [
					"id" => $row["id"],
					"is_game" => $isGame,
					"sender" => $row["sender"],
					"timestamp" => $row["timestamp"],
					"subject" => $parsed["subject"],
					"from_name" => $parsed["from_name"],
					"game" => $parsed["game"],
					"body" => $parsed["body"],
					"deleted_at" => $row["deleted_at"],
					"deleted_by" => $row["deleted_by"],
					// Distinguishes mail the game took a copy of from mail it
					// discarded unread; only meaningful for trashed messages.
					"retrieved" => $row["retrieved_at"] !== null,
					"unread" => $row["read_at"] === null,
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

			$stmt = $db->prepare("select username, dion_email_local from sys_users where id = ? limit 1");
			$stmt->bind_param("i", $fromUserId);
			$stmt->execute();
			$sender = $stmt->get_result()->fetch_assoc();
			if (!$sender) return [false, "no-sender"];

			$recipientId = $this->resolveLocalRecipient($toAddress);
			if ($recipientId === null) {
				// Off to the real internet: no Game Boy will ever render it,
				// so the 8-line / 96-character budget does not apply.
				return $this->sendExternal($fromUserId, $sender, $toAddress, $subject, $body);
			}

			// A message another player will read on a Game Boy: refused
			// outright when it cannot be displayed there, rather than sent
			// and silently truncated later.
			$tooLong = $this->checkSubjectFits($subject);
			if ($tooLong === null) $tooLong = $this->checkBodyFits($body);
			if ($tooLong !== null) return [false, $tooLong];

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
			$ok = $stmt->affected_rows > 0;
			if ($ok) $this->recordSent($fromUserId, $toAddress, $message);
			return [$ok, $ok ? "sent" : "insert-failed"];
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
		// Marks where the message came from, since everything leaving the
		// server passes through outboundRelay.js and it has no other way to
		// tell a webmail send from the game's. The relay strips this before
		// handing the message on, so it never reaches the recipient.
		const ORIGIN_HEADER = "X-REON-Origin";

		private function submitLocally($envelopeFrom, $recipient, $message) {
			$message = self::ORIGIN_HEADER . ": web\r\n" . $message;
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
			// A game's own mail is read-only in the webmail, and that is
			// enforced here rather than only by hiding the buttons: trashing a
			// trade result would take it away from the cartridge waiting to
			// collect it. An id that names one simply matches nothing.
			$notGame = "not ".$this->gameMailSql();
			$stmt = $db->prepare("$sqlHead where recipient = ? and $guard and $notGame and id in ($placeholders)");

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

		// Sent copies have no trash of their own: sys_sent has no deleted_at,
		// and a copy of something already delivered has nowhere to be
		// restored to. So removing one is final, and the button asks first.
		// Scoped by user_id (sys_sent's owner column) rather than by
		// recipient, which is why it cannot reuse bulk().
		public function deleteSentMany($userId, $ids) {
			$ids = array_values(array_filter(array_map("intval", (array)$ids)));
			if (empty($ids)) return 0;

			$db = DBUtil::getInstance()->getDB();
			$placeholders = implode(",", array_fill(0, count($ids), "?"));
			$stmt = $db->prepare("delete from sys_sent where user_id = ? and id in ($placeholders)");

			$params = array_merge([(int)$userId], $ids);
			$stmt->bind_param(str_repeat("i", count($params)), ...$params);
			$stmt->execute();
			return $stmt->affected_rows;
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

		// Messages sitting unread in the inbox. Counted per message, so the
		// badge falls by one each time something is opened rather than
		// clearing all at once when the list is viewed.
		public function countNewForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select count(*) as c from sys_inbox
				 where recipient = ? and deleted_at is null and read_at is null
				   and not " . $this->gameMailSql()
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Set when a message is opened in the webmail, once. Scoped by
		// recipient, so an id belonging to someone else marks nothing.
		public function markRead($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"update sys_inbox set read_at = now()
				 where id = ? and recipient = ? and read_at is null"
			);
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			return $stmt->affected_rows > 0;
		}

		// Sent copies, from the webmail and from the game. Parsed the same way
		// as received mail so the list can show subject and sender name.
		public function listSentForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, recipient, origin, timestamp, message from sys_sent
				 where user_id = ? order by timestamp desc, id desc"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$result = $stmt->get_result();

			$out = [];
			while ($row = $result->fetch_assoc()) {
				$parsed = $this->parse($row["message"]);
				$out[] = [
					"id" => $row["id"],
					"sender" => $row["recipient"],   // the list shows who it went to
					"recipient" => $row["recipient"],
					"origin" => $row["origin"],
					"timestamp" => $row["timestamp"],
					"subject" => $parsed["subject"],
					"from_name" => $row["recipient"],
					"game" => $parsed["game"],
					"body" => $parsed["body"],
					"unread" => false,
				];
			}
			return $out;
		}

		public function getSentForUser($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, recipient, origin, timestamp, message from sys_sent
				 where id = ? and user_id = ? limit 1"
			);
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (!$row) return null;

			$parsed = $this->parse($row["message"]);
			return [
				"id" => $row["id"],
				"sender" => $row["recipient"],
				"origin" => $row["origin"],
				"timestamp" => $row["timestamp"],
				"subject" => $parsed["subject"],
				"from_name" => $row["recipient"],
				"game" => $parsed["game"],
				"body" => $parsed["body"],
				"deleted_at" => null,
			];
		}

		public function countSentForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_sent where user_id = ?");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Recorded only for what this class delivers itself. Mail that leaves
		// through Postfix -- the game's, and the webmail's external sends --
		// is recorded by deliver.js and outboundRelay.js instead, so nothing
		// is written twice.
		private function recordSent($userId, $toAddress, $message) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_sent (user_id, recipient, origin, message) values (?, ?, 'web', ?)"
			);
			$userId = (int)$userId;
			$toAddress = substr((string)$toAddress, 0, 254);
			$stmt->bind_param("iss", $userId, $toAddress, $message);
			$stmt->execute();
		}

		public function countTrashForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_inbox where recipient = ? and deleted_at is not null and not " . $this->gameMailSql());
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
				// Read-only in the webmail: it is a game's own traffic, and
				// deleting or replying to it would break the game, not tidy
				// a mailbox.
				"is_game" => $this->isGameMail($parsed, $row["sender"]),
				"body" => $parsed["body"],
				"deleted_at" => $row["deleted_at"],
			];
		}

		// Waiting for a game to fetch it: still in the mailbox, and the game
		// has not taken a copy yet. Deliberately not "unread" -- nobody reads
		// these in the webmail, so read_at would never move.
		public function countGameWaitingForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select count(*) as c from sys_inbox
				 where recipient = ? and deleted_at is null and retrieved_at is null
				   and " . $this->gameMailSql()
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		public function countGameForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_inbox where recipient = ? and " . $this->gameMailSql());
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		public function countForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_inbox where recipient = ? and deleted_at is null and not " . $this->gameMailSql());
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// ------------------------------------------------------------------
		// Conversations. A webmail notion only: the games know nothing of
		// threads, and no header ties a reply to what it answers (the Mobile
		// Trainer sets none). So a conversation is the mail -- received and
		// sent -- that shares a subject once the "Re:"/"Fw:" prefixes are
		// stripped, with the same other party, however that party was
		// written (username, short or long address).
		// ------------------------------------------------------------------

		public function normalizeSubject($subject) {
			$s = trim((string)$subject);
			$prefix = '/^(re|fw|fwd|aw|sv|tr|res|vs|wg)\s*(\[\d+\])?\s*:\s*/iu';
			while (preg_match($prefix, $s)) {
				$s = preg_replace($prefix, "", $s, 1);
			}
			return mb_strtolower(trim(preg_replace('/\s+/u', " ", $s)));
		}

		private $partyCache = [];

		// The other side of a message, in a form that matches whether they
		// appeared as a sender (their address) or as a recipient (a
		// username, or an address at either of our domains): a REON account
		// becomes "u<id>", anyone else their lower-cased address.
		public function partyKey($address) {
			$a = trim((string)$address);
			if (preg_match('/<([^>]+)>/', $a, $m)) $a = $m[1];
			$a = mb_strtolower($a);
			if ($a === "") return "";
			if (!array_key_exists($a, $this->partyCache)) {
				$id = $this->resolveLocalRecipient($a);
				$this->partyCache[$a] = $id !== null ? "u" . $id : $a;
			}
			return $this->partyCache[$a];
		}

		public function isPlayerAddress($address) {
			return substr($this->partyKey($address), 0, 1) === "u";
		}

		// Every conversation of the inbox, newest activity first. Each carries
		// its messages oldest first (received and sent, bodies included), the
		// unread count, and the inbox ids a bulk action can act on.
		public function threadsForUser($userId) {
			$threads = [];
			foreach ($this->listForUser($userId, "inbox") as $m) {
				$m["kind"] = "in";
				$this->threadAdd($threads, $m, $this->partyKey($m["sender"]), $m["sender"], $m["from_name"]);
			}
			foreach ($this->listSentForUser($userId) as $m) {
				$m["kind"] = "out";
				$this->threadAdd($threads, $m, $this->partyKey($m["recipient"]), $m["recipient"], "");
			}
			foreach ($threads as &$t) {
				usort($t["messages"], function ($a, $b) {
					return strcmp($a["timestamp"], $b["timestamp"]) ?: ((int)$a["id"] <=> (int)$b["id"]);
				});
				$t["count"] = count($t["messages"]);
				$t["first"] = $t["messages"][0];
				$t["last"] = $t["messages"][$t["count"] - 1];
				// The oldest message names the conversation, without the
				// "Re:" every reply piles on.
				$t["subject"] = $t["first"]["subject"];
			}
			unset($t);
			usort($threads, function ($a, $b) {
				return strcmp($b["last"]["timestamp"], $a["last"]["timestamp"]);
			});
			return array_values($threads);
		}

		private function threadAdd(&$threads, $m, $partyKey, $partyAddress, $partyName) {
			$key = md5($this->normalizeSubject($m["subject"]) . "|" . $partyKey);
			if (!isset($threads[$key])) {
				$threads[$key] = [
					"key" => $key,
					"subject" => $m["subject"],
					"party" => $partyAddress,
					"party_name" => "",
					"party_is_player" => substr($partyKey, 0, 1) === "u",
					"messages" => [],
					"unread" => 0,
					"inbox_ids" => [],
					"has_sent" => false,
					"game" => "",
				];
			}
			$t = &$threads[$key];
			$t["messages"][] = $m;
			if ($m["kind"] === "in") {
				$t["inbox_ids"][] = (int)$m["id"];
				if ($m["unread"]) $t["unread"]++;
				// Received mail names the other side best: the From header
				// carries their display name; a sent copy only has an address.
				if ($partyName !== "" && $t["party_name"] === "") $t["party_name"] = $partyName;
				if ($m["game"] !== "" && $t["game"] === "") $t["game"] = $m["game"];
				$t["party"] = $partyAddress;
			} else {
				$t["has_sent"] = true;
			}
		}

		public function threadForUser($userId, $key) {
			$key = (string)$key;
			if (!preg_match('/^[0-9a-f]{32}$/', $key)) return null;
			foreach ($this->threadsForUser($userId) as $t) {
				if ($t["key"] === $key) return $t;
			}
			return null;
		}

		public function markReadMany($userId, $ids) {
			return $this->bulk($userId, $ids, "update sys_inbox set read_at = now()", "read_at is null");
		}

		// ------------------------------------------------------------------
		// Filtering, over the parsed rows: a text looked for in subject,
		// names, addresses and body, and one of the switches -- unread only,
		// players only, the real internet only.
		// ------------------------------------------------------------------

		const FILTERS = ["", "unread", "players", "internet"];

		public function messageMatches($m, $q, $only) {
			if ($only === "unread" && empty($m["unread"])) return false;
			if ($only === "players" || $only === "internet") {
				$address = (($m["kind"] ?? "in") === "out") ? ($m["recipient"] ?? "") : ($m["sender"] ?? "");
				if (($only === "players") !== $this->isPlayerAddress($address)) return false;
			}
			if ($q !== "") {
				$hay = mb_strtolower(implode("\n", [
					$m["subject"] ?? "", $m["from_name"] ?? "", $m["sender"] ?? "",
					$m["recipient"] ?? "", $m["body"] ?? "",
				]));
				if (mb_strpos($hay, mb_strtolower($q)) === false) return false;
			}
			return true;
		}

		public function filterMessages($rows, $q, $only) {
			return array_values(array_filter($rows, function ($m) use ($q, $only) {
				return $this->messageMatches($m, $q, $only);
			}));
		}

		// A conversation stays when any of its messages matches; "unread"
		// means the conversation has something unread.
		public function filterThreads($threads, $q, $only) {
			return array_values(array_filter($threads, function ($t) use ($q, $only) {
				if ($only === "unread") {
					if ($t["unread"] === 0) return false;
					$only = "";
				}
				foreach ($t["messages"] as $m) {
					if ($this->messageMatches($m, $q, $only)) return true;
				}
				return false;
			}));
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
				"from_name" => $this->fromDisplayName($headers["from"] ?? ""),
				"game" => $headers["x-game-title"] ?? "",
				// The same pair the Mobile Trainer itself tests before deciding
				// a message is not for it (proved by probe, 2026-09-10): a game
				// code plus "exclusive". Either alone is ordinary mail.
				"is_game" => isset($headers["x-game-code"])
					&& strtolower($headers["x-gbmail-type"] ?? "") === "exclusive",
				"body" => $this->decodeBody($body, $charset),
			];
		}

		// Whatever a From header can hand us: real inbound mail writes the
		// ordinary "Name <addr>" form, our own outbound game mail writes
		// "addr (Name)", and the exchange job's trade-result mail writes a
		// bare literal with neither ("MISSINGNO.", straight from the
		// original Mobile GB protocol, not an address at all). Empty means
		// none of those held a name; the template then falls back to the
		// stored sender column, which is correct for a bare address but was
		// wrongly reached for the last case -- that fallback is an internal
		// relay address the header itself already disagreed with.
		private function fromDisplayName($from) {
			$from = trim((string)$from);
			if ($from === "") return "";
			if (preg_match('/^"?([^"<]*?)"?\s*<[^>]+>\s*$/', $from, $m) && trim($m[1]) !== "") {
				return $this->decodeHeader(trim($m[1]));
			}
			if (preg_match('/\(([^)]*)\)/', $from, $m)) {
				return $this->decodeHeader($m[1]);
			}
			if (strpos($from, "@") === false && strpos($from, "<") === false) {
				return $this->decodeHeader($from);
			}
			return "";
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
