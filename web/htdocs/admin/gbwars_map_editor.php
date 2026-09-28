<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/GameboyWars3Util.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();

	// The GB Wars map creator. One URL, four jobs: the page itself (GET),
	// the file download of a saved draft (GET ?download=1), and the three
	// POSTs the page and the Maps list use -- save and publish (JSON, called
	// by map-editor.js) and copy (a plain form on the Maps tab that opens the
	// creator in a new tab).
	function gbwars_editor_json($data) {
		header("Content-Type: application/json");
		echo json_encode($data);
		exit;
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = (string)($_POST["form_action"] ?? "");

		if ($action === "save") {
			$in = json_decode((string)($_POST["draft"] ?? ""), true);
			if (!is_array($in)) {
				gbwars_editor_json(["ok" => false, "error" => "unreadable draft"]);
			}
			[$clean, $erro] = GameboyWars3Util::normalizeDraft($in);
			if ($clean === null) {
				gbwars_editor_json(["ok" => false, "error" => $erro]);
			}
			[$id, $erro] = GameboyWars3Util::saveDraft((int)($in["id"] ?? 0), $clean);
			$admin->log($erro === "" ? "gbwars.draft-save" : "gbwars.draft-save-failed", "draft=" . (int)$id, $erro);
			if ($id === null) {
				gbwars_editor_json(["ok" => false, "error" => $erro]);
			}
			gbwars_editor_json(["ok" => true, "id" => (int)$id]);
		}

		if ($action === "publish") {
			$draftId = (int)($_POST["id"] ?? 0);
			[$newId, $number, $erro] = GameboyWars3Util::publishDraft($draftId);
			$admin->log($erro === "" ? "gbwars.draft-publish" : "gbwars.draft-publish-failed", "draft=$draftId", $erro !== "" ? $erro : "map=$number");
			if ($newId === null) {
				gbwars_editor_json(["ok" => false, "error" => $erro]);
			}
			gbwars_editor_json(["ok" => true, "number" => $number]);
		}

		if ($action === "copy") {
			$mapDbId = (int)($_POST["map_id_db"] ?? 0);
			[$id, $erro] = GameboyWars3Util::draftFromMap($mapDbId);
			$admin->log($erro === "" ? "gbwars.draft-copy" : "gbwars.draft-copy-failed", "map=$mapDbId", $erro !== "" ? $erro : "draft=$id");
			if ($id !== null) {
				header("Location: /admin/gbwars_map_editor.php?id=" . (int)$id);
				exit;
			}
			echo TemplateUtil::render("admin/gbwars_map_editor", [
				"draft_json" => null,
				"notice" => TemplateUtil::translate("admin.gbwars-drafts-copy-failed") . " ($erro)",
				"notice_kind" => "bad",
			]);
			exit;
		}

		http_response_code(400);
		exit;
	}

	$id = (int)($_GET["id"] ?? 0);

	if ($id > 0 && isset($_GET["download"])) {
		$draft = GameboyWars3Util::getDraft($id);
		if ($draft === null) {
			http_response_code(404);
			exit;
		}
		// The number the file carries is the one a publish would store it
		// under; a hardware/emulator test of the download then sees what the
		// published map would show.
		$file = GameboyWars3Util::buildDraftFile($draft, GameboyWars3Util::nextReonMapId() ?? GameboyWars3Util::MAP_ID_REON_MIN);
		if (!GameboyWars3Util::validateMap($file, true)) {
			http_response_code(500);
			exit;
		}
		$admin->log("gbwars.draft-download", "draft=$id", "");
		header("Content-Type: application/octet-stream");
		header("Content-Disposition: attachment; filename=\"reon_draft_$id.cgb\"");
		header("Content-Length: " . strlen($file));
		echo $file;
		exit;
	}

	$notice = null;
	$noticeKind = "ok";
	if ($id > 0) {
		$draft = GameboyWars3Util::getDraft($id);
		if ($draft === null) {
			$notice = TemplateUtil::translate("admin.gbwars-drafts-not-found");
			$noticeKind = "bad";
			echo TemplateUtil::render("admin/gbwars_map_editor", ["draft_json" => null, "notice" => $notice, "notice_kind" => $noticeKind]);
			exit;
		}
		$clientDraft = GameboyWars3Util::draftForClient($draft);
	} else {
		$clientDraft = GameboyWars3Util::blankDraft();
	}

	// The strings the page's script needs, translated here so the script
	// itself carries no language: every key is admin.gbwars-editor-<name>.
	$keys = ["g_terrain", "g_neutral", "g_rs", "g_wm", "g_units_rs", "g_units_wm",
		"unsaved", "saving", "saved", "save_failed", "publishing", "published", "publish_failed", "publish_confirm",
		"published_as", "publish", "publish_again", "loading", "saved_state", "new_state",
		"err_too_long", "err_chars", "err_size", "err_session",
		"warn_rs_base", "warn_wm_base", "warn_no_name", "check_ok", "field_map_name", "field_category"];
	$i18n = [];
	foreach ($keys as $k) {
		$i18n[$k] = TemplateUtil::translate("admin.gbwars-editor-" . str_replace("_", "-", $k));
	}

	$config = [
		"draft" => $clientDraft,
		"i18n" => $i18n,
		"charset" => GameboyWars3Util::encodableChars(),
		"csrfField" => CsrfUtil::FIELD,
		"csrfToken" => CsrfUtil::token(),
		"endpoint" => "/admin/gbwars_map_editor.php",
		"lang" => substr((string)SessionUtil::getInstance()->getLocale(), 0, 2),
	];

	echo TemplateUtil::render("admin/gbwars_map_editor", [
		"draft_json" => json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
		"notice" => $notice,
		"notice_kind" => $noticeKind,
	]);
