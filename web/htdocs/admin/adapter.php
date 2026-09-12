<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/SettingsUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$cfg = SettingsUtil::getInstance();

	// O que esta tela edita. O resto do arquivo -- endereço de e-mail, gID,
	// chave de aparelho, token de relay -- sai do cadastro de cada pessoa e
	// não tem o que configurar aqui.
	$CAMPOS = [
		"bin_dns1_host", "bin_dns1_port",
		"bin_dns2_host", "bin_dns2_port",
		"bin_relay_host", "bin_relay_port",
		"bin_p2p_port",
		"bin_adapter_device", "bin_unmetered",
	];

	$notice = null;
	$noticeKind = "ok";
	$invalidos = [];

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		// Valida TUDO antes de gravar QUALQUER coisa. Gravar campo a campo
		// deixaria o arquivo metade novo e metade velho se o terceiro campo
		// fosse recusado -- e esse arquivo é lido por um cartucho, não por
		// uma tela que a pessoa possa corrigir depois.
		$novos = [];
		foreach ($CAMPOS as $k) {
			$v = trim((string)($_POST[$k] ?? ""));
			if ($k === "bin_unmetered") $v = ($v === "1") ? "1" : "0";
			if (!SettingsUtil::isValid($k, $v)) { $invalidos[] = $k; continue; }
			$novos[$k] = $v;
		}

		if ($invalidos) {
			$notice = TemplateUtil::translate("admin.adapter-invalid");
			$noticeKind = "bad";
		} else {
			$ok = true;
			foreach ($novos as $k => $v) $ok = $cfg->set($k, $v) && $ok;
			$admin->log($ok ? "adapter-config.change" : "adapter-config.change-failed",
				"mobile_config.bin", implode(" ", array_map(
					function ($k, $v) { return $k . "=" . $v; },
					array_keys($novos), $novos)));
			$notice = TemplateUtil::translate($ok ? "admin.adapter-saved" : "admin.adapter-failed");
			$noticeKind = $ok ? "ok" : "bad";
		}
	}

	$MODELOS = [8 => "Blue", 9 => "Yellow", 10 => "Green", 11 => "Red"];

	$valores = [];
	foreach ($CAMPOS as $k) $valores[$k] = $cfg->getValid($k);

	echo TemplateUtil::render("admin/adapter", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"invalidos" => $invalidos,
		"v" => $valores,
		// O dispositivo gravado hoje, em palavras. O byte que isso vira no
		// arquivo é detalhe de implementação e não tem por que aparecer numa
		// tela de administração.
		"device_words" => ($MODELOS[(int)$valores["bin_adapter_device"]] ?? "?") . " · " .
			TemplateUtil::translate($valores["bin_unmetered"] === "1"
				? "admin.adapter-unmetered-short" : "admin.adapter-metered"),
		"modelos" => $MODELOS,
	]);
