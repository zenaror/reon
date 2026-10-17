<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/AccountDataUtil.php");
	session_start();

	// Permanent account deletion requires CSRF validation, a freshly entered password and the
	// exact account name. These respectively prevent cross-site deletion, abuse of an unlocked
	// shared session and accidental confirmation.
	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /index.php");
		exit;
	}

	$db = DBUtil::getInstance()->getDB();
	$stmt = $db->prepare("select username, password from sys_users where id = ?");
	$stmt->bind_param("i", $_SESSION["user_id"]);
	$stmt->execute();
	$conta = DBUtil::fancy_get_result($stmt);
	if (!count($conta)) {
		header("Location: /index.php");
		exit;
	}
	$conta = $conta[0];

	$erros = [];

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		if (!password_verify((string)($_POST["password"] ?? ""), $conta["password"])) {
			$erros[] = "senha";
		}
		// Compare the account name exactly, including case, to confirm the user read the requested
		// name.
		if ((string)($_POST["confirmName"] ?? "") !== (string)$conta["username"]) {
			$erros[] = "nome";
		}

		if (!$erros) {
			$feito = AccountDataUtil::getInstance()->erase($_SESSION["user_id"]);
			if ($feito === null || (int)$feito["conta"] === 0) {
				$erros[] = "falhou";
			} else {
				// Destroy the session with the account so later pages cannot reference deleted
				// registration.
				$_SESSION = [];
				if (ini_get("session.use_cookies")) {
					$p = session_get_cookie_params();
					setcookie(session_name(), "", time() - 42000,
						$p["path"], $p["domain"], $p["secure"], $p["httponly"]);
				}
				session_destroy();
				header("Location: /index.php?deleted=1");
				exit;
			}
		}
	}

	echo TemplateUtil::render("/user/delete_account", [
		"username" => $conta["username"],
		"errors" => $erros,
	]);
