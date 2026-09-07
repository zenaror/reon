<?php
	require_once("DBUtil.php");

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
