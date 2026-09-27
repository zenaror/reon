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

	// Uma região por vez, escolhida na URL. As oito têm o mesmo formulário,
	// e listar as oito juntas faria uma tabela grande demais para ler.
	$regiao = strtolower((string)($_GET["region"] ?? "j"));
	if (!in_array($regiao, StadiumUtil::REGIONS, true)) $regiao = "j";

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$acao = (string)($_POST["action"] ?? "");
		$id = (int)($_POST["id"] ?? 0);

		if ($acao === "activate" || $acao === "deactivate") {
			$ok = StadiumUtil::setActive($id, $acao === "activate");
			$admin->log($ok ? "stadium.activate" : "stadium.activate-failed",
				"id=$id region=$regiao", $acao);
			$notice = TemplateUtil::translate($ok
				? "admin.stadium-saved" : "admin.stadium-failed");
			$noticeKind = $ok ? "ok" : "bad";
		}
	}

	$distribuicoes = StadiumUtil::allFor($regiao);

	echo TemplateUtil::render("admin/stadium", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"region" => $regiao,
		"regions" => StadiumUtil::REGIONS,
		"distributions" => $distribuicoes,
	]);
