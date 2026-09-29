<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/ServiceControlUtil.php");
	require_once("../../classes/LogViewUtil.php");
	session_start();

	AdminUtil::guard();

	$control = ServiceControlUtil::getInstance();

	// The services above, plus the one thing that is not a service: the PHP
	// site's own log, which is a file rather than a journal unit.
	const SITE = "@php";
	$sources = ServiceControlUtil::UNITS;
	$sources[SITE] = ["label" => "Site (PHP)"];

	$unit = isset($_GET["unit"]) && isset($sources[$_GET["unit"]]) ? $_GET["unit"] : array_key_first($sources);
	$lines = max(10, min(1000, (int)($_GET["lines"] ?? 200)));
	$level = in_array($_GET["level"] ?? "", ["warn", "error"], true) ? $_GET["level"] : "";
	$raw = !empty($_GET["raw"]);

	// Reading a log is a read, so it stays a GET -- but it is still a
	// privileged read, and it goes through the same helper as everything
	// else. Null means the helper is not installed; empty means the unit has
	// simply said nothing.
	if ($unit === SITE) {
		$kind = "file";
		$text = LogViewUtil::phpLogText();
	} else {
		$kind = "journal";
		$text = $control->journal($unit, $lines, LogViewUtil::journalPriority($level));
	}
	$entries = ($raw || $text === null) ? [] : LogViewUtil::parse($text, $kind, $level, $lines);

	echo TemplateUtil::render("admin/logs", [
		"units" => $sources,
		"unit" => $unit,
		"lines" => $lines,
		"level" => $level,
		"raw" => $raw,
		"text" => $text,
		"entries" => $entries,
		"is_file" => $unit === SITE,
		"available" => $unit === SITE ? $text !== null : $control->canReadJournal(),
		// Two different capabilities, asked separately: reading the journal
		// needs only group membership, restarting needs the helper.
		"web_user" => function_exists("posix_getpwuid") && function_exists("posix_geteuid")
			? (posix_getpwuid(posix_geteuid())["name"] ?? "?")
			: "?",
	]);
