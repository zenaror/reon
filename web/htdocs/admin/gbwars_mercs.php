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

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		$prices = [];
		$erro = "";
		for ($i = 0; $i < 5; $i++) {
			$raw = trim((string)($_POST["price$i"] ?? ""));
			if (!ctype_digit($raw)) { $erro = "price for unit $i must be a whole number"; break; }
			$prices[] = (int)$raw;
		}

		if ($erro === "") {
			$erro = GameboyWars3Util::setMercenaryPrices($prices);
		}

		$admin->log($erro === "" ? "gbwars.mercs-save" : "gbwars.mercs-save-failed", implode(",", $prices), $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.gbwars-mercs-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-mercs-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	$prices = GameboyWars3Util::getMercenaryPrices();

	echo TemplateUtil::render("admin/gbwars_mercs", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"prices" => $prices,
		"units" => GameboyWars3Util::MERCENARY_UNITS,
	]);
