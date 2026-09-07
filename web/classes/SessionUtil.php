<?php
	require_once(__DIR__."/DBUtil.php");

	class SessionUtil {

		private static $instance;

		private final function  __construct() {
		}

		public static function getInstance() {
			if(!isset(self::$instance)) {
				self::$instance = new SessionUtil();
			}
			return self::$instance;
		}
		
		public function isSessionActive() {
			return isset($_SESSION["user_id"]) && isset($_SESSION["type"]) && $_SESSION["type"] == "web";
		}
		
		public function initSession($userId) {
			$_SESSION["type"] = "web";
			$_SESSION["user_id"] = $userId;
			return;
		}

		public function getUsername() {
			if (!$this->isSessionActive()) return null;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select username from sys_users where id = ? limit 1");
			$userId = (int)$_SESSION["user_id"];
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$stmt->bind_result($username);
			$found = $stmt->fetch();
			$stmt->close();
			return $found ? $username : null;
		}

		// Read from the database on every call rather than cached into the
		// session at login: revoking admin then has effect immediately,
		// instead of waiting for that user's session to expire.
		public function isAdmin() {
			if (!$this->isSessionActive()) return false;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select is_admin from sys_users where id = ? limit 1");
			$userId = (int)$_SESSION["user_id"];
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$stmt->bind_result($isAdmin);
			$found = $stmt->fetch();
			$stmt->close();
			return $found && (int)$isAdmin === 1;
		}
		
		public function setLocale($locale) {
			$supported = array("en", "ja", "de", "es", "it", "fr", "pt-br");
			$normalized = strtolower(trim((string)$locale));
			if (!in_array($normalized, $supported, true)) {
				$normalized = "en";
			}
			$_SESSION["locale"] = $normalized;
			// TODO: Persist to user prefs if signed in
			return;
		}

		public function getLocale() {
			$supported = array("en", "ja", "de", "es", "it", "fr", "pt-br");
			// TODO: Initial value from user prefs if signed in
			if(isset($_GET["lang"])) {
				$this->setLocale($_GET["lang"]);
			}
			elseif (!isset($_SESSION["locale"])) {
				if(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && strlen($_SERVER['HTTP_ACCEPT_LANGUAGE'])>0) {
					$this->setLocale(substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2));
				} else {
					$_SESSION["locale"] = "en";
				}
			}
			elseif (!in_array(strtolower((string)$_SESSION["locale"]), $supported, true)) {
				$_SESSION["locale"] = "en";
			}
			return $_SESSION["locale"];
		}
		
		public function destroySession() {
			session_unset();
			session_destroy();
			return;
		}
	}
?>
