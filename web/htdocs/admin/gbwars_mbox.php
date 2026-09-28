<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/GameboyWars3Util.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "save") {
		CsrfUtil::check();

		$region = strtolower((string)($_POST["region"] ?? ""));
		$mailboxId = (int)($_POST["mailbox_id"] ?? -1);
		$title = trim((string)($_POST["title"] ?? ""));
		$body = (string)($_POST["body"] ?? "");
		$isActive = !empty($_POST["is_active"]);

		$erro = GameboyWars3Util::setMessage($region, $mailboxId, $title, $body, $isActive);

		$admin->log($erro === "" ? "gbwars.mbox-save" : "gbwars.mbox-save-failed", "region=$region mailbox=$mailboxId", $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.gbwars-mbox-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-mbox-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "toggle") {
		CsrfUtil::check();
		$region = strtolower((string)($_POST["region"] ?? ""));
		$mailboxId = (int)($_POST["mailbox_id"] ?? -1);
		$acao = (string)($_POST["action"] ?? "");
		$ok = GameboyWars3Util::setMessageActive($region, $mailboxId, $acao === "activate");
		$admin->log($ok ? "gbwars.mbox-toggle" : "gbwars.mbox-toggle-failed", "region=$region mailbox=$mailboxId", $acao);
		$notice = TemplateUtil::translate($ok ? "admin.gbwars-mbox-saved" : "admin.gbwars-mbox-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "delete") {
		CsrfUtil::check();
		$region = strtolower((string)($_POST["region"] ?? ""));
		$mailboxId = (int)($_POST["mailbox_id"] ?? -1);
		$ok = GameboyWars3Util::deleteMessage($region, $mailboxId);
		$admin->log($ok ? "gbwars.mbox-delete" : "gbwars.mbox-delete-failed", "region=$region mailbox=$mailboxId", "");
		$notice = TemplateUtil::translate($ok ? "admin.gbwars-mbox-deleted" : "admin.gbwars-mbox-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	// Editing an existing slot pre-fills the compose form -- decoded back
	// to plain title/body text, the same shape the form writes.
	$editRegion = strtolower((string)($_GET["edit_region"] ?? ""));
	$editMailbox = $_GET["edit_mailbox"] ?? null;
	$editTitle = "";
	$editBody = "";
	if (($editRegion === "j" || $editRegion === "e") && $editMailbox !== null) {
		$existing = GameboyWars3Util::getMessage($editRegion, (int)$editMailbox);
		if ($existing !== null) {
			[$editTitle, $editBody] = GameboyWars3Util::decodeMessage($existing["message_data"]);
		} else {
			$editMailbox = null;
		}
	} else {
		$editMailbox = null;
	}

	$messages = GameboyWars3Util::listMessages();

	echo TemplateUtil::render("admin/gbwars_mbox", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"messages" => $messages,
		"mailbox_max" => GameboyWars3Util::MAILBOX_MAX,
		"edit_region" => $editRegion !== "" ? $editRegion : "e",
		"edit_mailbox" => $editMailbox,
		"edit_title" => $editTitle,
		"edit_body" => $editBody,
	]);
