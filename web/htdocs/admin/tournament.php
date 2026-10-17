<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/SettingsUtil.php");
	require_once("../../classes/CaptureStoreUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$cfg = SettingsUtil::getInstance();
	$loja = CaptureStoreUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	// Download uses GET because it is read-only. Validate the filename through the capture
	// store before constructing its path; do not serve arbitrary filesystem paths.
	if (isset($_GET["file"])) {
		$caminho = $loja->path($_GET["file"]);
		if ($caminho === null) {
			http_response_code(404);
			exit;
		}
		// Audit who downloaded each capture: it contains two players communication, not merely
		// system diagnostics.
		$admin->log("tournament.download", basename($caminho),
			"bytes=" . filesize($caminho));
		header("Content-Type: application/jsonl; charset=utf-8");
		header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
		header("Content-Length: " . filesize($caminho));
		header("Cache-Control: no-store");
		readfile($caminho);
		exit;
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		// An absent checkbox means zero, otherwise capture mode would remain enabled after its
		// first activation.
		$novo = (($_POST["relay_capture"] ?? "") === "1") ? "1" : "0";
		$ok = $cfg->set("relay_capture", $novo);
		$admin->log($ok ? "tournament.mode" : "tournament.mode-failed",
			"relay_capture", $novo === "1" ? "on" : "off");
		$notice = TemplateUtil::translate($ok
			? "admin.tournament-saved" : "admin.tournament-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	$sessoes = $loja->sessions();
	// Find each capture counterpart here rather than performing list searches in the template.
	foreach ($sessoes as &$s) $s["peer"] = $loja->peerOf($s);
	unset($s);
	// Resolve both participant account names with one query for the complete list.
	$nomes = $loja->usernames($sessoes);

	echo TemplateUtil::render("admin/tournament", [
		"retention_days" => CaptureStoreUtil::RETENTION_DAYS,
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"on" => $loja->isOn(),
		"readable" => $loja->readable(),
		"directory" => CaptureStoreUtil::DIRECTORY,
		"sessions" => $sessoes,
		"usernames" => $nomes,
	]);
