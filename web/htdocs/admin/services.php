<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/ServiceControlUtil.php");
	require_once("../../classes/SettingsUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$control = ServiceControlUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["setting"])) {
		CsrfUtil::check();
		// Interruptor de política, não de serviço: não passa pelo auxiliar
		// privilegiado porque não há nada de privilegiado a fazer -- é uma
		// linha no banco que o Dovecot e o nosso POP3 leem na próxima conexão.
		$name = (string)$_POST["setting"];
		$value = ($_POST["value"] ?? "") === "1" ? "1" : "0";

		if (!SettingsUtil::isKnown($name)) {
			http_response_code(400);
			return;
		}

		$ok = SettingsUtil::getInstance()->set($name, $value);
		$admin->log($ok ? "setting.change" : "setting.change-failed", $name, $value);
		$notice = TemplateUtil::translate(
			$ok ? "admin.setting-saved" : "admin.setting-failed");
		$noticeKind = $ok ? "ok" : "bad";
	} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$unit = (string)($_POST["unit"] ?? "");
		$verb = (string)($_POST["verb"] ?? "");
		$schedule = trim((string)($_POST["schedule"] ?? ""));

		if (!ServiceControlUtil::isKnown($unit) || !in_array($verb, ServiceControlUtil::VERBS, true)) {
			http_response_code(400);
			return;
		}

		if (!$control->available()) {
			// Not an error to hide: the button exists, the permission does
			// not, and saying so is the only way anyone will go and install
			// it. Nothing was run.
			$notice = TemplateUtil::translate("admin.services-unavailable");
			$noticeKind = "bad";
		} else {
			[$ok, $detail] = $control->act($unit, $verb, $schedule);
			$admin->log($ok ? "service." . $verb : "service." . $verb . "-failed", $unit,
				$verb === "timer-set" ? $schedule . " — " . $detail : $detail);
			$notice = TemplateUtil::translate(
				$ok ? "admin.services-done" : "admin.services-failed",
				["%service%" => $unit, "%detail%" => $detail]
			);
			$noticeKind = $ok ? "ok" : "bad";
		}
	}

	echo TemplateUtil::render("admin/services", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"units" => $control->overview(),
		"available" => $control->available(),
		"pop3_password_fallback" => SettingsUtil::getInstance()->isOn("pop3_password_fallback"),
	]);
