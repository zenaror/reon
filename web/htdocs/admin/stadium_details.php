<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/StadiumUtil.php");
	session_start();

	AdminUtil::guard();

	// Read-only: shows what a stored payload actually decodes to, real
	// examples included -- the message text (tags and all), which slots
	// are filled, the flags, the File ID. Owner's request, 2026-09-28:
	// this explains the compose form's EUC-JP/markup hint better than
	// more prose would, and doubles as a reference for writing a new one.
	$id = (int)($_GET["id"] ?? 0);
	$row = StadiumUtil::getById($id);
	if ($row === null) {
		http_response_code(404);
		return;
	}

	$details = StadiumUtil::describePayload($row["game_region"], $row["payload"]);

	echo TemplateUtil::render("admin/stadium_details", [
		"row" => $row,
		"details" => $details,
	]);
