<?php
	// Cross-site request forgery guard for the web forms.
	//
	// The browser attaches the session cookie to any request aimed at this
	// site, whoever wrote the page that sent it. A form hidden on some other
	// site can therefore post to /user/change_password.php on the visitor's
	// behalf, and the server has no way to tell. The token is the one thing a
	// foreign page cannot know: it is bound to the session, written into every
	// form this server renders, and required back on every request that
	// changes state.
	//
	// The game's own paths (web/cgb/*) are deliberately untouched. They carry
	// their credentials in headers, not cookies, and no browser is involved --
	// there is nothing there for a foreign page to ride on.
	class CsrfUtil {
		const FIELD = "_csrf";

		// One token per session, made on first use so a page with no form
		// costs nothing, and kept until the session ends. A per-request token
		// would break the back button and any second tab.
		public static function token() {
			if (session_status() !== PHP_SESSION_ACTIVE) return "";
			if (empty($_SESSION["csrf_token"])) {
				$_SESSION["csrf_token"] = bin2hex(random_bytes(32));
			}
			return $_SESSION["csrf_token"];
		}

		// Call this first in any POST branch. Returns normally when the token
		// is present and matches; otherwise answers 403 and ends the request
		// before the handler reads a single field.
		public static function check() {
			$sent = isset($_POST[self::FIELD]) ? (string)$_POST[self::FIELD] : "";
			$held = isset($_SESSION["csrf_token"]) ? (string)$_SESSION["csrf_token"] : "";
			if ($held !== "" && $sent !== "" && hash_equals($held, $sent)) return;

			http_response_code(403);
			require_once(__DIR__."/TemplateUtil.php");
			echo TemplateUtil::render("csrf_error");
			exit();
		}

		// Discard the token, to be re-made on next use. Done when a session
		// turns from anonymous into signed-in, so a token a foreign page might
		// have coaxed out of the anonymous session does not carry across.
		public static function reset() {
			unset($_SESSION["csrf_token"]);
		}
	}
?>
