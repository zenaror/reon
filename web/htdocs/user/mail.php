<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/MailUtil.php");
	session_start();

	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /login.php");
		return;
	}

	$userId = $_SESSION["user_id"];
	$mail = MailUtil::getInstance();

	$folder = in_array($_GET["folder"] ?? "", ["trash", "sent"], true) ? $_GET["folder"] : "inbox";

	// The filter bar: free text plus one switch. Carried in the URL so a
	// filtered list can be refreshed, bookmarked, or returned to.
	$q = trim((string)($_GET["q"] ?? ""));
	$only = in_array($_GET["only"] ?? "", MailUtil::FILTERS, true) ? $_GET["only"] : "";
	$filter = ["q" => $q, "only" => $only, "active" => $q !== "" || $only !== ""];

	// Actions are POST-only so a crawler, a prefetch, or a stray <img> can
	// never destroy mail by being followed.
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = $_POST["action"] ?? "";

		// One code path for both cases: a single-message button posts one id,
		// the list's checkboxes post many, and each becomes a list of ids.
		$ids = isset($_POST["ids"]) ? (array)$_POST["ids"] : [];
		if (isset($_POST["id"])) $ids[] = $_POST["id"];
		// A conversation's checkbox carries every received message of the
		// conversation as "id,id,id", so one tick acts on the whole thing.
		$ids = array_merge(...array_map(function ($v) { return explode(",", (string)$v); }, $ids ?: [""]));

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
		"sent_count" => $mail->countSentForUser($userId),
					"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"internal_domains" => $mail->internalDomains(),
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
		"sent_count" => $mail->countSentForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"internal_domains" => $mail->internalDomains(),
		]);
		return;
	}

	if (isset($_GET["thread"])) {
		$thread = $mail->threadForUser($userId, $_GET["thread"]);
		if ($thread === null) {
			http_response_code(404);
		} elseif (!empty($thread["inbox_ids"])) {
			// Opened means read, as for a single message. The unread flags in
			// $thread are from before this, so the view can still mark what
			// was new.
			$mail->markReadMany($userId, $thread["inbox_ids"]);
		}
		echo TemplateUtil::render("/user/mail", [
			"message" => null,
			"messages" => null,
			"thread" => $thread,
			"compose" => null,
			"folder" => "inbox",
			"trash_count" => $mail->countTrashForUser($userId),
			"sent_count" => $mail->countSentForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"internal_domains" => $mail->internalDomains(),
		]);
		return;
	}

	if (isset($_GET["id"])) {
		$message = $folder === "sent"
			? $mail->getSentForUser($userId, $_GET["id"])
			: $mail->getForUser($userId, $_GET["id"]);
		if ($message === null) {
			http_response_code(404);
		} elseif ($folder !== "sent") {
			// Marked on open, which is the only moment the webmail can honestly
			// claim the message was read. Sent mail has no unread state.
			$mail->markRead($userId, $message["id"]);
		}
		echo TemplateUtil::render("/user/mail", [
			"message" => $message,
			"messages" => null,
			"compose" => null,
			"folder" => $folder,
			"trash_count" => $mail->countTrashForUser($userId),
		"sent_count" => $mail->countSentForUser($userId),
			"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"internal_domains" => $mail->internalDomains(),
		]);
		return;
	}

	// The inbox is read as conversations (received and sent together);
	// Sent and Trash stay flat lists of messages.
	$messages = null;
	$threads = null;
	if ($folder === "inbox") {
		// Only conversations with something received belong in the inbox;
		// one made of your own messages alone lives in Sent, as it always
		// did (and has nothing a checkbox here could act on).
		$threads = array_values(array_filter($mail->threadsForUser($userId), function ($t) {
			return !empty($t["inbox_ids"]);
		}));
		$threads = $mail->filterThreads($threads, $q, $only);
	} else {
		$rows = $folder === "sent" ? $mail->listSentForUser($userId) : $mail->listForUser($userId, $folder);
		$messages = $mail->filterMessages($rows, $q, $only);
	}

	echo TemplateUtil::render("/user/mail", [
		"message" => null,
		"messages" => $messages,
		"threads" => $threads,
		"filter" => $filter,
		"compose" => null,
		"sent" => isset($_GET["sent"]),
		"folder" => $folder,
		"trash_count" => $mail->countTrashForUser($userId),
		"sent_count" => $mail->countSentForUser($userId),
		"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		"body_max_lines" => MailUtil::BODY_MAX_LINES,
		"body_max_chars" => MailUtil::BODY_MAX_CHARS,
	]);
