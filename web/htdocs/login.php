<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/CsrfUtil.php");
	require_once("../classes/DBUtil.php");
	require_once("../classes/SessionUtil.php");
	session_start();

	// Where to go after logging in: a page that sent the visitor here to
	// do something signed-in (download config.bin from the guide, say).
	// Only a local path is honoured -- never a full URL, never "//host".
	function login_next($raw) {
		$raw = (string)$raw;
		if ($raw === "" || $raw[0] !== "/" || (isset($raw[1]) && $raw[1] === "/")) return "";
		if (preg_match('/[\r\n]/', $raw)) return "";
		return $raw;
	}
	$next = login_next($_REQUEST["next"] ?? "");

	if ($_SERVER["REQUEST_METHOD"] == "POST") {
		CsrfUtil::check();
		if (!(isset($_POST["email"]) && isset($_POST["password"]))) return;
		$db = DBUtil::getInstance()->getDB();
		// Either the e-mail address or the REON username. The two can never
		// collide: a username is [a-z0-9]{3,20} and an address must contain
		// "@", so no string is a valid form of both. The 8-character
		// dion_email_local is deliberately not accepted here -- it is unique
		// on its own, but nothing stops one account's short form from equalling
		// another account's username, and that would be ambiguous.
		$identifier = trim($_POST["email"]);
		// A banned account is refused here, at the one door where a password
		// is checked, rather than by hiding pages from it afterwards. It
		// falls through to the same "wrong details" answer: telling someone
		// their credentials were right but the account is banned is telling
		// an attacker their credentials were right.
		$stmt = $db->prepare("select id, password, email from sys_users where (email = ? or username = ?) and banned_at is null limit 1");
		$stmt->bind_param("ss", $identifier, $identifier);
		$stmt->execute();
		$result = DBUtil::fancy_get_result($stmt);
		if (array_key_exists(0, $result) && password_verify($_POST["password"], $result[0]["password"])) {
			SessionUtil::getInstance()->initSession($result[0]["id"]);
			//$_SESSION["user_email"] = $result[0]["email"];
			header("Location: ".($next !== "" ? $next : "index.php"));
		} else {
			echo TemplateUtil::render("login", [
				"login_fail" => true,
				"next" => $next,
			]);
		}
	} else {
		echo TemplateUtil::render("login", ["next" => $next]);
	}