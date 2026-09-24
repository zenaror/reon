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

	// Baixar. GET, porque é leitura, e o nome passa pela peneira da loja
	// antes de virar caminho -- servir arquivo arbitrário de um diretório é
	// como "baixar log" se transforma em "ler qualquer coisa do disco".
	if (isset($_GET["file"])) {
		$caminho = $loja->path($_GET["file"]);
		if ($caminho === null) {
			http_response_code(404);
			exit;
		}
		// Quem baixou o quê fica no registro: é conversa de dois jogadores,
		// não log de sistema.
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
		// Caixa desmarcada não chega no POST: a ausência é "0", e não "não
		// mexeu" -- que deixaria o modo ligado para sempre depois da
		// primeira vez.
		$novo = (($_POST["relay_capture"] ?? "") === "1") ? "1" : "0";
		$ok = $cfg->set("relay_capture", $novo);
		$admin->log($ok ? "tournament.mode" : "tournament.mode-failed",
			"relay_capture", $novo === "1" ? "on" : "off");
		$notice = TemplateUtil::translate($ok
			? "admin.tournament-saved" : "admin.tournament-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	$sessoes = $loja->sessions();
	// O par de cada gravação, calculado aqui e não no template: é uma busca
	// na lista, e o template não é lugar de fazer busca.
	foreach ($sessoes as &$s) $s["peer"] = $loja->peerOf($s);
	unset($s);

	echo TemplateUtil::render("admin/tournament", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"on" => $loja->isOn(),
		"readable" => $loja->readable(),
		"directory" => CaptureStoreUtil::DIRECTORY,
		"sessions" => $sessoes,
	]);
