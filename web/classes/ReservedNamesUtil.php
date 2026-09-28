<?php
	require_once(__DIR__ . "/DBUtil.php");

	// Usernames nobody may register: the names that would pass for the
	// service itself, for staff, for another company, or that collide with a
	// page or a role address mail systems treat as special.
	//
	// The list lives in the database (sys_reserved_usernames) so it can be
	// edited from the admin panel, with a comment on every entry to keep it
	// organised. Until 2026-09-28 the only thing keeping "system" and
	// "nintendo" out of reach was that two accounts with those names happened
	// to exist; delete the accounts and the names were free to take.
	//
	// Only NEW sign-ups are checked. An account that already carries a
	// reserved name keeps it -- reserving a name never takes one away.
	class ReservedNamesUtil {

		const MIN = 3;   // = UserUtil::USERNAME_MIN: shorter names cannot be registered anyway
		const MAX = 20;  // = UserUtil::USERNAME_MAX
		const COMMENT_MAX = 160;

		// Used ONLY when the table does not exist yet (a server that has not
		// run the migration), so the two names that started this are never
		// left unprotected. The real list is the table.
		const FALLBACK = ["system", "nintendo", "reon", "reonteam", "admin", "administrator", "root", "staff", "official", "postmaster", "noreply"];

		// Lower-case [a-z0-9], MIN..MAX long, or null. Usernames can only be
		// lower-case anyway, so the list is kept in the same shape.
		public static function normalize($name) {
			$name = strtolower(trim((string)$name));
			$len = strlen($name);
			if ($len < self::MIN || $len > self::MAX) return null;
			if (!preg_match('/^[a-z0-9]+$/', $name)) return null;
			return $name;
		}

		public static function isReserved($username) {
			$name = strtolower(trim((string)$username));
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare("select 1 from sys_reserved_usernames where username = ? limit 1");
				$stmt->bind_param("s", $name);
				$stmt->execute();
				return $stmt->get_result()->fetch_row() !== null;
			} catch (\mysqli_sql_exception $e) {
				// Missing table (migration not run): fall back rather than
				// leave the names unprotected or break sign-up.
				return in_array($name, self::FALLBACK, true);
			}
		}

		// Every entry, with whether an account already uses the name.
		public static function all() {
			$db = DBUtil::getInstance()->getDB();
			return $db->query(
				"select r.username, r.comment, r.created_at,
				        exists(select 1 from sys_users u where u.username = r.username) as in_use
				   from sys_reserved_usernames r
				  order by r.username")->fetch_all(MYSQLI_ASSOC);
		}

		// Adds one name. Returns "" or an error key (admin.err-<key>).
		public static function add($name, $comment, $adminId = null) {
			$norm = self::normalize($name);
			if ($norm === null) return "reserved-bad-name";
			$comment = trim((string)$comment);
			if (mb_strlen($comment) > self::COMMENT_MAX) return "reserved-comment-long";
			$db = DBUtil::getInstance()->getDB();
			$c = $comment === "" ? null : $comment;
			$by = $adminId === null ? null : (int)$adminId;
			$stmt = $db->prepare("insert ignore into sys_reserved_usernames (username, comment, created_by) values (?, ?, ?)");
			$stmt->bind_param("ssi", $norm, $c, $by);
			$stmt->execute();
			return $stmt->affected_rows > 0 ? "" : "reserved-exists";
		}

		public static function setComment($name, $comment) {
			$norm = self::normalize($name);
			if ($norm === null) return "reserved-bad-name";
			$comment = trim((string)$comment);
			if (mb_strlen($comment) > self::COMMENT_MAX) return "reserved-comment-long";
			$db = DBUtil::getInstance()->getDB();
			$c = $comment === "" ? null : $comment;
			$stmt = $db->prepare("update sys_reserved_usernames set comment = ? where username = ?");
			$stmt->bind_param("ss", $c, $norm);
			$stmt->execute();
			return "";
		}

		public static function remove($name) {
			$norm = self::normalize($name);
			if ($norm === null) return "reserved-bad-name";
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("delete from sys_reserved_usernames where username = ?");
			$stmt->bind_param("s", $norm);
			$stmt->execute();
			return "";
		}

		// "name # comment" per line. A line that starts with # is a note to
		// whoever reads the list and is skipped; blank lines too. Returns
		// [entries, problems] where entries is [[name, comment], ...] and
		// problems is a list of the raw lines that could not be used.
		public static function parseBulk($text) {
			$entries = [];
			$problems = [];
			foreach (preg_split('/\R/', (string)$text) as $line) {
				$line = trim($line);
				if ($line === "" || $line[0] === "#") continue;
				$parts = explode("#", $line, 2);
				$name = self::normalize($parts[0]);
				$comment = isset($parts[1]) ? trim($parts[1]) : "";
				if ($name === null || mb_strlen($comment) > self::COMMENT_MAX) {
					$problems[] = mb_substr($line, 0, 60);
					continue;
				}
				$entries[] = [$name, $comment];
			}
			return [$entries, $problems];
		}
	}
