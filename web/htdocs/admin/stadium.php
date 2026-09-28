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

	// One region at a time, chosen in the URL. All eight have the same
	// form, and listing the eight together would make a table too big to
	// read. Composing (below) only needs the FORMAT the region belongs to
	// (StadiumUtil::formatFor()) -- western regions all compose the same
	// way, into storeWestern(), the same as before this page absorbed the
	// compose form from stadium_compose.php (owner's request, 2026-09-28:
	// split into "replays" and "compose + manage built distributions").
	$regiao = strtolower((string)($_GET["region"] ?? "j"));
	if (!in_array($regiao, StadiumUtil::REGIONS, true)) $regiao = "j";
	$formato = StadiumUtil::formatFor($regiao);

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "activate") {
		CsrfUtil::check();
		$acao = (string)($_POST["action"] ?? "");
		$id = (int)($_POST["id"] ?? 0);

		if ($acao === "activate" || $acao === "deactivate") {
			// setActive(true) now deactivates every other row on the same
			// (region, track) first -- at most one active distribution per
			// region (owner's request, 2026-09-28).
			$ok = StadiumUtil::setActive($id, $acao === "activate");
			$admin->log($ok ? "stadium.activate" : "stadium.activate-failed",
				"id=$id region=$regiao", $acao);
			$notice = TemplateUtil::translate($ok
				? "admin.stadium-saved" : "admin.stadium-failed");
			$noticeKind = $ok ? "ok" : "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "compose") {
		CsrfUtil::check();

		$slotIds = [];
		foreach ([1, 2, 3] as $n) {
			$v = trim((string)($_POST["slot$n"] ?? ""));
			if ($v !== "") $slotIds[] = (int)$v;
		}

		$replayRecords = [];
		$replayRows = [];
		$erro = "";
		foreach ($slotIds as $id) {
			$linha = StadiumUtil::replayById($id);
			if ($linha === null) { $erro = "replay #$id not found"; break; }
			if ($linha["format"] !== $formato) { $erro = "replay #$id is not a '$formato' record"; break; }
			$replayRecords[] = $linha["record"];
			$replayRows[] = $linha;
		}

		// Whether the composed distribution itself claims to be official or
		// custom -- a separate choice from what the picked replays are
		// (owner's point, 2026-09-28: composing is not the same decision
		// as uploading). Checked against the picked replays below.
		$composeIsCustom = !empty($_POST["compose_is_custom"]);
		if ($erro === "") {
			$erro = StadiumUtil::composeIsAllowed($composeIsCustom, $replayRows);
		}

		$flags = 0;
		if (!empty($_POST["flag_gb_to_gba"])) $flags |= 0x01;
		if (!empty($_POST["flag_n64_to_gc"])) $flags |= 0x02;

		$costKind = (string)($_POST["cost_kind"] ?? "0");
		if ($costKind === "none") {
			$cost = null;
		} else if ($costKind === "n") {
			$cost = (int)($_POST["cost_n"] ?? 0);
		} else {
			$cost = 0;
		}

		$slug = strtolower(trim((string)($_POST["slug"] ?? "")));
		$mensagem = (string)($_POST["message"] ?? "");
		$titulo = trim((string)($_POST["title"] ?? ""));

		if ($erro === "" && count($replayRecords) === 0) {
			$erro = "pick at least one replay";
		}

		if ($erro === "") {
			// A fresh File ID every time, so re-composing always looks like
			// new data to a console that already has the previous one
			// (spec.md §5.1: "changing the File ID is what makes clients
			// download again"). Form: ASCII date + 8 random hex chars,
			// following the precedent in the real saves
			// ("20260802TestFile").
			$fileId = date("Ymd") . "RC" . substr(bin2hex(random_bytes(3)), 0, 6);
			[$payload, $erro] = StadiumUtil::composePayload($formato, $replayRecords, null, $mensagem, $flags, $fileId);
		}

		if ($erro === "") {
			// A custom distribution is gated by
			// sys_users.custom_mobile_stadium_opt_in the same way a
			// custom Pokémon News track is gated; an official one is not.
			if ($formato === "j") {
				$erro = StadiumUtil::storeJapanese($fileId, $payload, $cost, $slug, $titulo ?: null, "composed-1", $composeIsCustom);
			} else {
				$falhas = StadiumUtil::storeWestern($fileId, $payload, $cost, $slug, $titulo ?: null, "composed-1", $composeIsCustom);
				if (count($falhas) > 0) $erro = implode("; ", $falhas);
			}
		}

		$admin->log($erro === "" ? "stadium.compose" : "stadium.compose-failed",
			"format=$formato slots=" . implode(",", $slotIds), $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.stadium-compose-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.stadium-compose-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	$distribuicoes = StadiumUtil::allFor($regiao);
	$replays = StadiumUtil::replaysFor($formato);

	echo TemplateUtil::render("admin/stadium", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"region" => $regiao,
		"regions" => StadiumUtil::REGIONS,
		"distributions" => $distribuicoes,
		"format" => $formato,
		"replays" => $replays,
	]);
