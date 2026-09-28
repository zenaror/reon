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

	// Three tabs, one page load each: the published maps, a file upload, and
	// the map creator's drafts. Every form posts back to its own tab.
	$tab = (string)($_GET["tab"] ?? "maps");
	if (!in_array($tab, ["maps", "upload", "drafts"], true)) $tab = "maps";

	function gbwars_maps_parse_price($raw) {
		$raw = trim((string)$raw);
		if ($raw === "") return null; // null -> importMap()/updateMapMeta() apply the 10-yen default
		return (int)$raw;
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "upload") {
		CsrfUtil::check();

		$price = gbwars_maps_parse_price($_POST["upload_price"] ?? "");
		$nameE = trim((string)($_POST["upload_name_e"] ?? ""));
		$categoryE = trim((string)($_POST["upload_category_e"] ?? ""));
		$erro = "";

		if (!isset($_FILES["map"]) || $_FILES["map"]["error"] !== UPLOAD_ERR_OK) {
			$erro = "no file received (or the upload failed)";
		} else {
			$bytes = file_get_contents($_FILES["map"]["tmp_name"]);
			[$newId, $erro] = [null, ""];
			[$newId, $erro] = GameboyWars3Util::importMap($bytes, $price, $nameE !== "" ? $nameE : null, $categoryE !== "" ? $categoryE : null);
		}

		$admin->log($erro === "" ? "gbwars.map-upload" : "gbwars.map-upload-failed", "", $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.gbwars-maps-upload-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-maps-upload-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "edit") {
		CsrfUtil::check();

		$id = (int)($_POST["map_id_db"] ?? 0);
		$price = gbwars_maps_parse_price($_POST["edit_price"] ?? "");
		$nameE = trim((string)($_POST["edit_name_e"] ?? ""));
		$categoryE = trim((string)($_POST["edit_category_e"] ?? ""));

		$erro = GameboyWars3Util::updateMapMeta($id, $price, $nameE !== "" ? $nameE : null, $categoryE !== "" ? $categoryE : null);

		$admin->log($erro === "" ? "gbwars.map-edit" : "gbwars.map-edit-failed", "id=$id", $erro);

		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.gbwars-maps-edit-saved");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-maps-edit-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "activate") {
		CsrfUtil::check();
		$id = (int)($_POST["map_id_db"] ?? 0);
		$acao = (string)($_POST["action"] ?? "");
		$ok = GameboyWars3Util::setMapActive($id, $acao === "activate");
		$admin->log($ok ? "gbwars.map-activate" : "gbwars.map-activate-failed", "id=$id", $acao);
		$notice = TemplateUtil::translate($ok ? "admin.gbwars-maps-edit-saved" : "admin.gbwars-maps-edit-failed");
		$noticeKind = $ok ? "ok" : "bad";
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "publish_draft") {
		CsrfUtil::check();
		$draftId = (int)($_POST["draft_id"] ?? 0);
		[$newId, $number, $erro] = GameboyWars3Util::publishDraft($draftId);
		$admin->log($erro === "" ? "gbwars.draft-publish" : "gbwars.draft-publish-failed", "draft=$draftId", $erro !== "" ? $erro : "map=$number");
		if ($erro === "") {
			$notice = sprintf(TemplateUtil::translate("admin.gbwars-drafts-published"), $number);
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-drafts-publish-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST" && (string)($_POST["form_action"] ?? "") === "delete_draft") {
		CsrfUtil::check();
		$draftId = (int)($_POST["draft_id"] ?? 0);
		$erro = GameboyWars3Util::deleteDraft($draftId);
		$admin->log($erro === "" ? "gbwars.draft-delete" : "gbwars.draft-delete-failed", "draft=$draftId", $erro);
		if ($erro === "") {
			$notice = TemplateUtil::translate("admin.gbwars-drafts-deleted");
			$noticeKind = "ok";
		} else {
			$notice = TemplateUtil::translate("admin.gbwars-drafts-delete-failed") . " ($erro)";
			$noticeKind = "bad";
		}
	}

	$maps = GameboyWars3Util::listMaps();
	// Pre-computed here, not compared in the template: Twig comparing a
	// zero-padded CHAR(4) map_id ("1001") against an int is exactly the
	// kind of implicit-coercion corner PHP and Twig do not always agree
	// on, and this is only ever true/false per row anyway.
	foreach ($maps as &$m) {
		$m["is_official"] = GameboyWars3Util::isOfficialMap((int)$m["map_id"]);
	}
	unset($m);

	echo TemplateUtil::render("admin/gbwars_maps", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"maps" => $maps,
		"tab" => $tab,
		"drafts" => $tab === "drafts" ? GameboyWars3Util::listDrafts() : [],
	]);
