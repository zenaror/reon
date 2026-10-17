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

	// Edit only shared adapter fields here. Account email, gID, device key and relay token are
	// derived from individual registration.
	$CAMPOS = [
		"bin_dns1_host", "bin_dns1_port",
		"bin_dns2_host", "bin_dns2_port",
		"bin_relay_host", "bin_relay_port",
		"bin_p2p_port",
		"bin_adapter_device", "bin_unmetered", "bin_user_choice",
	];

	$notice = null;
	$noticeKind = "ok";
	$invalidos = [];

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		// Validate all fields before saving any, avoiding partially updated adapter configuration
		// when a later field is rejected.
		$novos = [];
		foreach ($CAMPOS as $k) {
			$v = trim((string)($_POST[$k] ?? ""));
			// Unchecked checkboxes are absent from POST; treat absence as zero rather than leave a
			// previously enabled flag unchanged.
			if ($k === "bin_unmetered" || $k === "bin_user_choice") $v = ($v === "1") ? "1" : "0";
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

	// Exclude model 10 (green) by operator policy. The gap is intentional: these values are
	// libmobile enum IDs, not list positions.
	$MODELOS = [8 => "Blue", 9 => "Yellow", 11 => "Red"];

	$valores = [];
	foreach ($CAMPOS as $k) $valores[$k] = $cfg->getValid($k);

	echo TemplateUtil::render("admin/adapter", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"invalidos" => $invalidos,
		"v" => $valores,
		// Show the current adapter model as a readable name; its encoded byte is an implementation
		// detail.
		"device_words" => ($MODELOS[(int)$valores["bin_adapter_device"]] ?? "?") . " · " .
			TemplateUtil::translate($valores["bin_unmetered"] === "1"
				? "admin.adapter-unmetered-short" : "admin.adapter-metered"),
		"modelos" => $MODELOS,
	]);
