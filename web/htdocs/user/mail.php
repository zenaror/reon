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
		} elseif ($action === "send") {
			$to = trim($_POST["to"] ?? "");
			$subject = trim($_POST["subject"] ?? "");
			$body = (string)($_POST["body"] ?? "");

			if ($to === "" || $body === "") {
				$error = "empty";
			} else {
				[$ok, $reason] = $mail->send($userId, $to, $subject, $body);
				$error = $ok ? null : $reason;
			}

			// The form is re-rendered with what was typed still in it when
			// something is wrong -- retyping a message because of a typo in
			// the address would be its own small betrayal.
			if (isset($error) && $error !== null) {
				echo TemplateUtil::render("/user/mail", [
					"message" => null,
					"messages" => null,
					"compose" => ["to" => $to, "subject" => $subject, "body" => $body],
					"compose_error" => $error,
					"folder" => "inbox",
					"trash_count" => $mail->countTrashForUser($userId),
					"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
				]);
				return;
			}
			$back = "/user/mail.php?sent=1";
		} else {
			$back = "/user/mail.php";
		}

		// Redirect after POST so a refresh doesn't replay the action.
		header("Location: " . $back);
		return;
	}

	// Compose is its own view rather than a panel on the list, so a long
	// message has the whole width to be written in.
	if (isset($_GET["compose"])) {
		echo TemplateUtil::render("/user/mail", [
			"message" => null,
			"messages" => null,
			"compose" => ["to" => trim($_GET["to"] ?? ""), "subject" => "", "body" => ""],
			"compose_error" => null,
			"folder" => "inbox",
			"trash_count" => $mail->countTrashForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		]);
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
			"compose" => null,
			"folder" => $folder,
			"trash_count" => $mail->countTrashForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		]);
		return;
	}

	echo TemplateUtil::render("/user/mail", [
		"message" => null,
		"messages" => $mail->listForUser($userId, $folder),
		"compose" => null,
		"sent" => isset($_GET["sent"]),
		"folder" => $folder,
		"trash_count" => $mail->countTrashForUser($userId),
		"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
	]);
