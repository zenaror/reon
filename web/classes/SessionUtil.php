<?php
	require_once(__DIR__."/DBUtil.php");

	// Session cookie hardening. Every web handler calls session_start() on
	// its own, and every one of them requires this file first, so file scope
	// here is the one place guaranteed to run before any of them.
	//
	//   samesite=Lax  the browser withholds the cookie from POSTs that start
	//                 on another site, which is the ride CSRF depends on.
	//                 Browsers assume Lax when nothing is declared, and the
	//                 site was living on that assumption; declaring it makes
	//                 the protection ours rather than a default we happened
	//                 to inherit.
	//   secure        never sent over plain HTTP -- but only set when the
	//                 request itself came over HTTPS. The port-80 vhost also
	//                 answers for this hostname and is shared with the game's
	//                 hostnames, which a Game Boy can only reach in the clear;
	//                 an unconditional Secure would leave an HTTP visitor
	//                 unable to keep a session at all.
	//   httponly      unreadable from JavaScript, so a script that finds its
	//                 way into a page cannot lift the session with it.
	//
	// use_strict_mode is left alone on purpose: the game's auth path in
	// web/cgb/auth.php creates sessions under ids it chooses itself, which
	// strict mode would refuse.
	ini_set("session.cookie_samesite", "Lax");
	ini_set("session.cookie_secure", (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "1" : "0");
	ini_set("session.cookie_httponly", "1");

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
			// The session turns from anonymous into signed-in here, so two
			// things it carried until now must not carry across: its id (a
			// fixed id planted before login would otherwise become a
			// signed-in one) and the CSRF token a foreign page might have
			// coaxed out of the anonymous session. Both are re-made.
			session_regenerate_id(true);
			unset($_SESSION["csrf_token"]);
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
