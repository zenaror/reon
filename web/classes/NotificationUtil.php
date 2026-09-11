<?php
	require_once("DBUtil.php");
	require_once("TemplateUtil.php");

	// The bell in the header. Everything a player should be told about but
	// that is not a letter: a trade that completed or failed to, a game whose
	// content was refreshed, an announcement someone wrote by hand.
	//
	// The one rule that shapes this class: **the reader cannot delete
	// anything.** A notification is the record that a thing happened, and a
	// record you can erase is not one. Marking it read is the only state a
	// reader owns. There is deliberately no delete method here at all -- not
	// a guarded one, not an admin-only one -- so no handler can grow a route
	// to it by accident.
	//
	// Notifications never replace the mail indicators. A letter arriving
	// produces both: the orange mail badge, which says "there is something to
	// read in your mailbox", and a notification, which says "this happened at
	// this time". They answer different questions and the owner asked for
	// both.
	class NotificationUtil {

		private static $instance;

		// What a notification is about, which is also how it is coloured.
		//   mail    a letter arrived
		//   trade   a Trade Corner exchange resolved, either way
		//   game    a game's own content changed (news, rankings, rooms)
		//   system  the service itself (maintenance, service restored)
		//   admin   written by a person through the admin panel
		const CATEGORIES = ["mail", "trade", "game", "system", "admin"];

		// The history page never asks for more than this at once.
		const PAGE_SIZE = 20;

		// What the bell's dropdown shows before "see all".
		const RECENT = 8;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new NotificationUtil();
			}
			return self::$instance;
		}

		private function normalizeCategory($category) {
			$category = strtolower(trim((string)$category));
			return in_array($category, self::CATEGORIES, true) ? $category : "system";
		}

		// Adds one notification for one user.
		//
		// $opts keys, all optional:
		//   key     translation id for the headline ("notify.trade-done").
		//           Automatic callers use this so the words follow the
		//           reader's language rather than the writer's.
		//   params  values substituted into that string.
		//   title   a literal headline, for text a person typed. Stored
		//           verbatim, because translating someone's own words is not
		//           ours to do.
		//   body    a detail line. Allowed with either of the two above.
		//   link    where clicking it should go.
		//   game    which game it came from, for the label on the row.
		//   batch   ties one announcement's fan-out together.
		//   by      the admin who wrote it.
		//
		// Returns the new id, or null when the insert did not take. Callers
		// are cron jobs and delivery paths whose real work already succeeded,
		// so a failure here must never be fatal to them.
		public function add($userId, $category, $opts = []) {
			$db = DBUtil::getInstance()->getDB();

			$userId = (int)$userId;
			if ($userId <= 0) return null;

			$category = $this->normalizeCategory($category);
			$game = isset($opts["game"]) ? substr((string)$opts["game"], 0, 40) : null;
			$key = isset($opts["key"]) ? substr((string)$opts["key"], 0, 64) : null;
			$params = isset($opts["params"]) && $opts["params"] !== []
				? json_encode($opts["params"], JSON_UNESCAPED_UNICODE)
				: null;
			$title = isset($opts["title"]) ? substr(trim((string)$opts["title"]), 0, 160) : null;
			$body = isset($opts["body"]) ? (string)$opts["body"] : null;
			$link = isset($opts["link"]) ? substr((string)$opts["link"], 0, 255) : null;
			$batch = isset($opts["batch"]) ? substr((string)$opts["batch"], 0, 40) : null;
			$by = isset($opts["by"]) ? (int)$opts["by"] : null;

			// Nothing to show is not a notification.
			if ($key === null && ($title === null || $title === "")) return null;

			$stmt = $db->prepare(
				"insert into sys_notifications
				 (user_id, category, game, message_key, params, title, body, link, batch, created_by)
				 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
			);
			$stmt->bind_param("issssssssi",
				$userId, $category, $game, $key, $params, $title, $body, $link, $batch, $by);
			$stmt->execute();
			$id = $stmt->insert_id;
			$stmt->close();
			return $id > 0 ? $id : null;
		}

		// The same notification to every account, as one row each. Returns
		// how many were written, and the batch id they share.
		public function addForAll($category, $opts = []) {
			$db = DBUtil::getInstance()->getDB();
			$batch = isset($opts["batch"]) ? $opts["batch"] : bin2hex(random_bytes(8));
			$opts["batch"] = $batch;

			$sent = 0;
			$result = $db->query("select id from sys_users");
			if ($result) {
				while ($row = $result->fetch_assoc()) {
					if ($this->add($row["id"], $category, $opts) !== null) $sent++;
				}
			}
			return [$sent, $batch];
		}

		public function countUnread($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_notifications where user_id = ? and read_at is null");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			$stmt->close();
			return $row ? (int)$row["c"] : 0;
		}

		public function countForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_notifications where user_id = ?");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			$stmt->close();
			return $row ? (int)$row["c"] : 0;
		}

		// Newest first. Scoped by user in the query itself, so an id from
		// somewhere else simply is not in the result.
		//
		// $unreadOnly is what the bell's dropdown asks for. The dropdown is a
		// tray of what is new, not a second copy of the history: once
		// something has been read it belongs on the history page and nowhere
		// else, so the menu empties as it is read rather than accumulating
		// everything that ever happened.
		public function listForUser($userId, $limit = self::PAGE_SIZE, $offset = 0, $unreadOnly = false) {
			$db = DBUtil::getInstance()->getDB();
			$onlyNew = $unreadOnly ? " and read_at is null" : "";
			$stmt = $db->prepare(
				"select id, category, game, message_key, params, title, body, link, created_at, read_at
				   from sys_notifications
				  where user_id = ?$onlyNew
				  order by id desc
				  limit ? offset ?"
			);
			$userId = (int)$userId;
			$limit = max(1, (int)$limit);
			$offset = max(0, (int)$offset);
			$stmt->bind_param("iii", $userId, $limit, $offset);
			$stmt->execute();
			$result = $stmt->get_result();

			$rows = [];
			while ($row = $result->fetch_assoc()) {
				$rows[] = $this->present($row);
			}
			$stmt->close();
			return $rows;
		}

		// Turns a stored row into what a template shows: the headline
		// resolved into the reader's language when it was stored as a key,
		// and the timestamp as an integer the |date filter can take.
		private function present($row) {
			$params = [];
			if (!empty($row["params"])) {
				$decoded = json_decode($row["params"], true);
				if (is_array($decoded)) {
					foreach ($decoded as $k => $v) {
						// Symfony's translator matches placeholders literally,
						// so the stored names are wrapped here rather than in
						// every caller.
						$params["%".trim((string)$k, "%")."%"] = (string)$v;
					}
				}
			}

			$title = (string)$row["title"];
			if (!empty($row["message_key"])) {
				$title = TemplateUtil::translate($row["message_key"], $params);
				// A key with no translation comes back as itself, which would
				// put "notify.trade-done" on the screen. The literal title is
				// the better fallback when there is one.
				if ($title === $row["message_key"] && !empty($row["title"])) {
					$title = (string)$row["title"];
				}
			}

			return [
				"id" => (int)$row["id"],
				"category" => $row["category"],
				"game" => $row["game"],
				"title" => $title,
				"body" => $row["body"],
				"link" => $row["link"],
				"created_at" => strtotime($row["created_at"]),
				"unread" => $row["read_at"] === null,
			];
		}

		// Opening the bell is reading them: the dropdown shows the text, so
		// pretending otherwise would leave the badge lit over things the
		// reader has already seen.
		public function markAllRead($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_notifications set read_at = now() where user_id = ? and read_at is null");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$n = $stmt->affected_rows;
			$stmt->close();
			return $n;
		}

		public function markRead($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_notifications set read_at = now() where user_id = ? and id = ? and read_at is null");
			$userId = (int)$userId;
			$id = (int)$id;
			$stmt->bind_param("ii", $userId, $id);
			$stmt->execute();
			$stmt->close();
		}
	}
?>
