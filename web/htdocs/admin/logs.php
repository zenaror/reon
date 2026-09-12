<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/ServiceControlUtil.php");
	session_start();

	AdminUtil::guard();

	$control = ServiceControlUtil::getInstance();

	$units = array_keys(ServiceControlUtil::UNITS);
	$unit = isset($_GET["unit"]) && ServiceControlUtil::isKnown($_GET["unit"]) ? $_GET["unit"] : $units[0];
	$lines = max(10, min(1000, (int)($_GET["lines"] ?? 200)));

	// Reading a log is a read, so it stays a GET -- but it is still a
	// privileged read, and it goes through the same helper as everything
	// else. Null means the helper is not installed; empty means the unit has
	// simply said nothing.
	$text = $control->journal($unit, $lines);

	echo TemplateUtil::render("admin/logs", [
		"units" => ServiceControlUtil::UNITS,
		"unit" => $unit,
		"lines" => $lines,
		"text" => $text,
		// Two different capabilities, asked separately: reading the journal
		// needs only group membership, restarting needs the helper.
		"available" => $control->canReadJournal(),
		"web_user" => function_exists("posix_getpwuid") && function_exists("posix_geteuid")
			? (posix_getpwuid(posix_geteuid())["name"] ?? "?")
			: "?",
	]);
