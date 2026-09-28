<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/StadiumUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	// Replay-library actions are FORMAT-scoped (j/w), not region-scoped --
	// a record's byte layout depends only on JP against western
	// (StadiumUtil::formatFor()), the same reasoning as
	// stadium_compose.php had. This page owns upload and management only;
	// composing them into a distribution, and managing what gets built,
	// live on stadium.php now (owner's request, 2026-09-28, to split what
	// used to be one page into "replays" and "compose + built
	// distributions").
	$formato = strtolower((string)($_GET["format"] ?? "j"));
	if ($formato !== "j" && $formato !== "w") $formato = "j";

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "upload") {
		CsrfUtil::check();

		$label = trim((string)($_POST["upload_label"] ?? ""));
		$sourceNote = trim((string)($_POST["upload_source_note"] ?? ""));
		// Required editorial call, not a default: see StadiumUtil::storeReplay()'s
		// own comment on why a replay's official/custom status cannot be
		// inferred from the file itself.
		$isCustom = !empty($_POST["upload_is_custom"]);
		$erro = "";

		if (!isset($_FILES["record"]) || $_FILES["record"]["error"] !== UPLOAD_ERR_OK) {
			$erro = "no file received (or the upload failed)";
		} else {
			$bytes = file_get_contents($_FILES["record"]["tmp_name"]);
			$erro = StadiumUtil::storeReplay($formato, $bytes, $label, $sourceNote !== "" ? $sourceNote : null, $isCustom);
		}

		$admin->log($erro === "" ? "stadium.replay-upload" : "stadium.replay-upload-failed",
			"format=$formato label=$label is_custom=" . ($isCustom ? "1" : "0"), $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.stadium-replays-upload-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.stadium-replays-upload-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "rename_replay") {
		CsrfUtil::check();

		$id = (int)($_POST["replay_id"] ?? 0);
		$label = trim((string)($_POST["rename_label"] ?? ""));
		$sourceNote = trim((string)($_POST["rename_source_note"] ?? ""));
		$isCustom = !empty($_POST["rename_is_custom"]);

		$erro = StadiumUtil::renameReplay($id, $label, $sourceNote !== "" ? $sourceNote : null, $isCustom);

		$admin->log($erro === "" ? "stadium.replay-rename" : "stadium.replay-rename-failed",
			"id=$id label=$label is_custom=" . ($isCustom ? "1" : "0"), $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.stadium-replays-rename-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.stadium-replays-rename-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "delete_replay") {
		CsrfUtil::check();

		$id = (int)($_POST["replay_id"] ?? 0);
		$ok = StadiumUtil::deleteReplay($id);

		$admin->log($ok ? "stadium.replay-delete" : "stadium.replay-delete-failed", "id=$id", "");

		$notice = TemplateUtil::translate($ok ? "admin.stadium-replays-delete-saved" : "admin.stadium-replays-delete-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	$replays = StadiumUtil::replaysFor($formato);

	echo TemplateUtil::render("admin/stadium_replays", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"format" => $formato,
		"replays" => $replays,
	]);
