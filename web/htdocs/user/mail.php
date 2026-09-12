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
	// Read once, then tested. Written as `in_array($_GET["only"] ?? "", ...)
	// ? $_GET["only"] : ""` this warned on every visit with no filter: "" is
	// itself a valid filter, so the test passed and the branch went back to
	// read a key that was never there. Thousands of lines of
	// "Undefined array key" in the log, from the ordinary case.
	$only = (string)($_GET["only"] ?? "");
	if (!in_array($only, MailUtil::FILTERS, true)) $only = "";
	$filter = ["q" => $q, "only" => $only, "active" => $q !== "" || $only !== ""];

	// Rows per page. Kept in the session rather than only in the URL so the
	// choice survives switching folders and coming back later, which is what
	// picking a page size is for.
	if (isset($_GET["per"]) && in_array((int)$_GET["per"], MailUtil::PAGE_SIZES, true)) {
		$_SESSION["mail_per_page"] = (int)$_GET["per"];
	}
	$per = $_SESSION["mail_per_page"] ?? MailUtil::PAGE_SIZE_DEFAULT;
	if (!in_array($per, MailUtil::PAGE_SIZES, true)) $per = MailUtil::PAGE_SIZE_DEFAULT;
	$page = max(1, (int)($_GET["page"] ?? 1));

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
		} elseif ($action === "delete-sent") {
			// Its own action rather than reusing "delete": that one is scoped
			// to sys_inbox's trash, and these ids belong to sys_sent.
			$mail->deleteSentMany($userId, $ids);
			$back = "/user/mail.php?folder=sent";
		} elseif ($action === "send") {
			$to = trim($_POST["to"] ?? "");
			$subject = trim($_POST["subject"] ?? "");
			$body = (string)($_POST["body"] ?? "");
			$replyId = $_POST["reply"] ?? null;
			$replySentId = $_POST["reply_sent"] ?? null;
			$threadKey = null;

			// Nem o destino nem o título de uma resposta saem do formulário:
			// os dois são re-derivados aqui da mensagem respondida, do mesmo
			// jeito que a tela os preencheu. Os campos aparecem travados, mas
			// travar é aparência -- a garantia é esta linha, porque um POST
			// cru não passa por atributo nenhum de HTML.
			//
			// Junto vem a conversa que a resposta continua, e é ela que faz a
			// mensagem entrar na thread certa em vez de ser adivinhada pelo
			// título depois.
			$ctx = $mail->replyContext($userId, $replyId, $replySentId);
			if ($ctx !== null) {
				$to = $ctx["to"];
				$subject = $ctx["subject"];
				$threadKey = $ctx["thread_key"];
			}

			if ($to === "" || $body === "") {
				$error = "empty";
			} else {
				[$ok, $reason] = $mail->send($userId, $to, $subject, $body, $threadKey);
				$error = $ok ? null : $reason;
			}

			// The form is re-rendered with what was typed still in it when
			// something is wrong -- retyping a message because of a typo in
			// the address would be its own small betrayal.
			if (isset($error) && $error !== null) {
				echo TemplateUtil::render("/user/mail", [
					"message" => null,
					"messages" => null,
					"compose" => ["to" => $to, "subject" => $subject, "body" => $body,
						"reply_id" => $replyId, "reply_sent_id" => $replySentId],
					"compose_error" => $error,
					"folder" => "inbox",
					"trash_count" => $mail->countTrashForUser($userId),
		"sent_count" => $mail->countSentForUser($userId),
					"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
			"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_line_chars" => MailUtil::BODY_MAX_LINE_CHARS,
			"subject_max_chars" => MailUtil::SUBJECT_MAX_CHARS,
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
		$prefill = ["to" => trim($_GET["to"] ?? ""), "subject" => "", "body" => "",
			"reply_id" => null, "reply_sent_id" => null];

		// Responder é endereçado por id de mensagem, e nunca pela URL trazendo
		// endereço e título prontos: as duas buscas são limitadas ao dono, de
		// modo que só se pode responder a algo que é dele.
		//
		// São DOIS parâmetros porque são dois espaços de nomes diferentes --
		// "reply" é id na caixa do Dovecot, "reply_sent" é id de linha em
		// sys_sent. Antes havia só um, e o botão da tela de Enviados mandava
		// o id dele pelo parâmetro da entrada: a busca não achava nada, o
		// formulário abria com destinatário e título em branco, e os dois
		// campos ainda por cima destravados.
		$prefillCtx = $mail->replyContext(
			$userId, $_GET["reply"] ?? null, $_GET["reply_sent"] ?? null);
		if ($prefillCtx !== null) {
			$prefill["to"] = $prefillCtx["to"];
			$prefill["subject"] = $prefillCtx["subject"];
			$prefill["reply_id"] = $_GET["reply"] ?? null;
			$prefill["reply_sent_id"] = $_GET["reply_sent"] ?? null;
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
			"body_max_line_chars" => MailUtil::BODY_MAX_LINE_CHARS,
			"subject_max_chars" => MailUtil::SUBJECT_MAX_CHARS,
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
			"body_max_line_chars" => MailUtil::BODY_MAX_LINE_CHARS,
			"subject_max_chars" => MailUtil::SUBJECT_MAX_CHARS,
			"body_max_chars" => MailUtil::BODY_MAX_CHARS,
			"internal_domains" => $mail->internalDomains(),
		]);
		return;
	}

	if (isset($_GET["id"])) {
		$message = $folder === "sent"
			? $mail->getSentForUser($userId, $_GET["id"])
			: $mail->getForUser($userId, $_GET["id"]);
		// A game's own mail has no reading view: the body is a cartridge's
		// binary payload, and the page that would show it is the page that
		// offers replying and deleting. Nothing lists these any more, so this
		// is the id typed by hand -- and it gets the same answer as a message
		// that is not yours. The check stays here, not in the template: a
		// listing can be removed, an SQL guard cannot be walked around.
		if ($message !== null && !empty($message["is_game"])) {
			$message = null;
		}
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
			"body_max_line_chars" => MailUtil::BODY_MAX_LINE_CHARS,
			"subject_max_chars" => MailUtil::SUBJECT_MAX_CHARS,
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
		[$threads, $pagination] = $mail->paginate($threads, $page, $per);
	} else {
		$rows = $folder === "sent" ? $mail->listSentForUser($userId) : $mail->listForUser($userId, $folder);
		$messages = $mail->filterMessages($rows, $q, $only);
		[$messages, $pagination] = $mail->paginate($messages, $page, $per);
	}

	echo TemplateUtil::render("/user/mail", [
		"message" => null,
		"messages" => $messages,
		"threads" => $threads,
		"filter" => $filter,
		"pagination" => $pagination,
		"compose" => null,
		"sent" => isset($_GET["sent"]),
		"folder" => $folder,
		"trash_count" => $mail->countTrashForUser($userId),
		"sent_count" => $mail->countSentForUser($userId),
		"retention_days" => MailUtil::TRASH_RETENTION_DAYS,
		"body_max_lines" => MailUtil::BODY_MAX_LINES,
			"body_max_line_chars" => MailUtil::BODY_MAX_LINE_CHARS,
			"subject_max_chars" => MailUtil::SUBJECT_MAX_CHARS,
		"body_max_chars" => MailUtil::BODY_MAX_CHARS,
	]);
