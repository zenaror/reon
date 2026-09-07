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
			$result = $mail->restoreMany($userId, $ids);
			// Carried back in the URL rather than swallowed: a restore that
			// silently did nothing is worse than one that says why.
			$back = $result["ok"]
				? "/user/mail.php?folder=trash"
				: "/user/mail.php?folder=trash&full=" . $result["free"];
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
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"inbox_max" => MailUtil::INBOX_MAX,
			"restore_blocked" => null,
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
		$prefill = ["to" => trim($_GET["to"] ?? ""), "subject" => "", "body" => ""];

		// Replying is addressed by message id, not by handing the address and
		// subject over in the URL: getForUser is scoped by recipient, so this
		// can only ever pre-fill from a message that belongs to the caller.
		if (isset($_GET["reply"])) {
			$original = $mail->getForUser($userId, $_GET["reply"]);
			if ($original !== null) {
				$subject = trim((string)$original["subject"]);
				$prefill["to"] = $original["sender"];
				$prefill["subject"] = preg_match('/^re:\s/i', $subject) ? $subject : ("Re: " . $subject);
			}
		}

		echo TemplateUtil::render("/user/mail", [
			"message" => null,
			"messages" => null,
			"compose" => $prefill,
			"compose_error" => null,
			"folder" => "inbox",
			"trash_count" => $mail->countTrashForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"inbox_max" => MailUtil::INBOX_MAX,
			"restore_blocked" => null,
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
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"inbox_max" => MailUtil::INBOX_MAX,
			"restore_blocked" => null,
		]);
		return;
	}

	// Marked before rendering, so the badge this very page draws already
	// reflects that the inbox has just been looked at. Only the inbox counts:
	// the trash is not where new mail arrives.
	if ($folder === "inbox") {
		$mail->markInboxSeen($userId);
	}

	echo TemplateUtil::render("/user/mail", [
		"message" => null,
		"messages" => $mail->listForUser($userId, $folder),
		"compose" => null,
		"sent" => isset($_GET["sent"]),
		"folder" => $folder,
		"trash_count" => $mail->countTrashForUser($userId),
		"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		"body_max_lines" => MailUtil::BODY_MAX_LINES,
		"body_max_chars" => MailUtil::BODY_MAX_CHARS,
		"inbox_max" => MailUtil::INBOX_MAX,
		"restore_blocked" => isset($_GET["full"]) ? (int)$_GET["full"] : null,
	]);
