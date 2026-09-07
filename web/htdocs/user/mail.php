<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/MailUtil.php");
	session_start();

	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /login.php");
		return;
	}

	$userId = $_SESSION["user_id"];
	$mail = MailUtil::getInstance();

	$folder = (isset($_GET["folder"]) && $_GET["folder"] === "trash") ? "trash" : "inbox";

	// Actions are POST-only so a crawler, a prefetch, or a stray <img> can
	// never destroy mail by being followed.
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		$action = $_POST["action"] ?? "";

		// One code path for both cases: a single-message button posts one id,
		// the list's checkboxes post many, and each becomes a list of ids.
		$ids = isset($_POST["ids"]) ? (array)$_POST["ids"] : [];
		if (isset($_POST["id"])) $ids[] = $_POST["id"];

		// Every one of these is scoped by recipient inside MailUtil, so ids
		// belonging to someone else simply affect nothing.
		if ($action === "trash") {
			$mail->moveToTrashMany($userId, $ids);
			$back = "/user/mail.php";
		} elseif ($action === "restore") {
			$mail->restoreMany($userId, $ids);
			$back = "/user/mail.php?folder=trash";
		} elseif ($action === "delete") {
			$mail->deleteForeverMany($userId, $ids);
			$back = "/user/mail.php?folder=trash";
		} else {
			$back = "/user/mail.php";
		}

		// Redirect after POST so a refresh doesn't replay the action.
		header("Location: " . $back);
		return;
	}

	if (isset($_GET["id"])) {
		$message = $mail->getForUser($userId, $_GET["id"]);
		if ($message === null) {
			http_response_code(404);
		}
		echo TemplateUtil::render("/user/mail", [
			"message" => $message,
			"messages" => null,
			"folder" => $folder,
			"trash_count" => $mail->countTrashForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		]);
		return;
	}

	echo TemplateUtil::render("/user/mail", [
		"message" => null,
		"messages" => $mail->listForUser($userId, $folder),
		"folder" => $folder,
		"trash_count" => $mail->countTrashForUser($userId),
		"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
	]);
