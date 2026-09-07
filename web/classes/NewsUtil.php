<?php
	require_once("DBUtil.php");
	require_once(__DIR__."/../vendor/autoload.php");

	use League\CommonMark\CommonMarkConverter;

	// Site news posts. Bodies are stored as Markdown and rendered at display
	// time (not pre-rendered at save time), so a change to the renderer or
	// its settings applies to every post, old ones included.
	class NewsUtil {

		private static $instance;
		private $converter;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new NewsUtil();
			}
			return self::$instance;
		}

		private function __construct() {
			// Raw HTML is escaped rather than passed through: posts are
			// admin-authored, but that is an authorization boundary, not a
			// reason to hand any author a script-injection primitive.
			$this->converter = new CommonMarkConverter([
				"html_input" => "escape",
				"allow_unsafe_links" => false,
			]);
		}

		public function render($markdown) {
			return (string)$this->converter->convert((string)$markdown);
		}

		// URL-safe slug derived from the title, with a numeric suffix if
		// that slug is already taken.
		public function slugify($title, $ignoreId = null) {
			$slug = strtolower(trim((string)$title));
			$slug = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $slug);
			$slug = preg_replace("/[^a-z0-9]+/", "-", $slug);
			$slug = trim($slug, "-");
			if ($slug === "") $slug = "post";

			$db = DBUtil::getInstance()->getDB();
			$base = substr($slug, 0, 150);
			$slug = $base;
			for ($n = 2; ; $n++) {
				$stmt = $db->prepare("select id from sys_news where slug = ? limit 1");
				$stmt->bind_param("s", $slug);
				$stmt->execute();
				$stmt->store_result();
				$taken = $stmt->num_rows > 0;
				if ($taken && $ignoreId !== null) {
					$stmt->bind_result($foundId);
					$stmt->fetch();
					if ((int)$foundId === (int)$ignoreId) $taken = false;
				}
				$stmt->close();
				if (!$taken) return $slug;
				$slug = $base."-".$n;
			}
		}

		// Published posts only, newest first. $limit/$offset drive both the
		// homepage box (top 5) and the paginated /news.php listing.
		public function getPublished($limit, $offset = 0) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select id, slug, title, body, published_at from sys_news
				where published_at is not null and published_at <= now()
				order by published_at desc limit ? offset ?
			");
			$limit = (int)$limit;
			$offset = (int)$offset;
			$stmt->bind_param("ii", $limit, $offset);
			$stmt->execute();
			return $this->fetchAll($stmt);
		}

		public function countPublished() {
			$db = DBUtil::getInstance()->getDB();
			$result = $db->query("
				select count(*) as c from sys_news
				where published_at is not null and published_at <= now()
			");
			return $result ? (int)$result->fetch_assoc()["c"] : 0;
		}

		public function getBySlug($slug) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select id, slug, title, body, published_at from sys_news
				where slug = ? and published_at is not null and published_at <= now()
				limit 1
			");
			$stmt->bind_param("s", $slug);
			$stmt->execute();
			$rows = $this->fetchAll($stmt);
			return count($rows) > 0 ? $rows[0] : null;
		}

		// Admin listing: includes drafts (published_at null) and posts
		// scheduled for the future.
		public function getAll() {
			$db = DBUtil::getInstance()->getDB();
			$result = $db->query("
				select id, slug, title, body, published_at from sys_news
				order by coalesce(published_at, created_at) desc
			");
			$rows = [];
			while ($row = $result->fetch_assoc()) $rows[] = $row;
			return $rows;
		}

		public function getById($id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select id, slug, title, body, published_at from sys_news where id = ? limit 1
			");
			$id = (int)$id;
			$stmt->bind_param("i", $id);
			$stmt->execute();
			$rows = $this->fetchAll($stmt);
			return count($rows) > 0 ? $rows[0] : null;
		}

		public function create($title, $body, $published) {
			$db = DBUtil::getInstance()->getDB();
			$slug = $this->slugify($title);
			$publishedAt = $published ? date("Y-m-d H:i:s") : null;
			$stmt = $db->prepare("
				insert into sys_news (slug, title, body, published_at) values (?, ?, ?, ?)
			");
			$stmt->bind_param("ssss", $slug, $title, $body, $publishedAt);
			$stmt->execute();
			return $db->insert_id;
		}

		public function update($id, $title, $body, $published) {
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$existing = $this->getById($id);
			if ($existing === null) return false;

			$slug = $this->slugify($title, $id);
			// Keep the original publication date when a post is already
			// published, so editing a typo doesn't bump it to the top of
			// the list.
			if (!$published) {
				$publishedAt = null;
			} else if ($existing["published_at"] !== null) {
				$publishedAt = $existing["published_at"];
			} else {
				$publishedAt = date("Y-m-d H:i:s");
			}

			$stmt = $db->prepare("
				update sys_news set slug = ?, title = ?, body = ?, published_at = ? where id = ?
			");
			$stmt->bind_param("ssssi", $slug, $title, $body, $publishedAt, $id);
			$stmt->execute();
			return true;
		}

		public function delete($id) {
			$db = DBUtil::getInstance()->getDB();
			$id = (int)$id;
			$stmt = $db->prepare("delete from sys_news where id = ?");
			$stmt->bind_param("i", $id);
			$stmt->execute();
			return true;
		}

		private function fetchAll(&$stmt) {
			$result = $stmt->get_result();
			$rows = [];
			while ($row = $result->fetch_assoc()) $rows[] = $row;
			$stmt->close();
			return $rows;
		}
	}
?>
