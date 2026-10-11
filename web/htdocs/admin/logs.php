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
	const ACTIVITY = "@activity";
	$sources = [ACTIVITY => ['label'=>TemplateUtil::translate('admin.logs-source-activity')], SITE => ['label'=>TemplateUtil::translate('admin.logs-source-php')]];
    $sources += array_filter(ServiceControlUtil::UNITS, fn($meta) => empty($meta['hidden']));

	$unit = isset($_GET["unit"]) && is_string($_GET["unit"]) && isset($sources[$_GET["unit"]]) ? $_GET["unit"] : ACTIVITY;
	$lines = in_array((int)($_GET["lines"] ?? 200), [50,100,200,500,1000], true) ? (int)($_GET["lines"] ?? 200) : 200;
	$level = in_array($_GET["level"] ?? "", ["warn", "error"], true) ? $_GET["level"] : "";
	$raw = !empty($_GET["raw"]);
	$group = is_string($_GET["group"] ?? "") && isset(LogViewUtil::EVENT_GROUPS[$_GET["group"] ?? ""]) ? $_GET["group"] : "";

    $game = strtoupper((string)($_GET['game'] ?? ''));
    if (!preg_match('/^(?:AGB|CGB|SGB)-[A-Z0-9]{4}$/D', $game)) $game = '';
    $date = function ($v) { return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v ? $v : ''; };
    $from = $date((string)($_GET['from'] ?? '')); $to = $date((string)($_GET['to'] ?? ''));

	// Reading a log is a read, so it stays a GET -- but it is still a
	// privileged read, and it goes through the same helper as everything
	// else. Null means the helper is not installed; empty means the unit has
	// simply said nothing.
	if ($unit === SITE || $unit === ACTIVITY) {
		$kind = "file";
		$text = $unit === ACTIVITY
			? (LogViewUtil::historyText(LogViewUtil::ACTIVITY_LOG) ?? (is_readable(dirname(LogViewUtil::ACTIVITY_LOG)) ? "" : null))
			: LogViewUtil::historyText(LogViewUtil::PHP_LOG);
	} else {
		$kind = "journal";
		$text = $control->journal($unit, ($game !== "" || $from !== "" || $to !== "") ? 1000 : $lines, LogViewUtil::journalPriority($level));
	}
	$entries = ($text === null) ? [] : LogViewUtil::parse($text, $kind, $level, $lines, $unit === ACTIVITY ? $group : "", $game, $from, $to);

    if ($raw && $text !== null) $text = implode("\n", array_map(fn($e) => ($e['time_utc'] ?? '').' '.($e['level'] ?? '').' '.($e['msg'] ?? '').(($e['extra'] ?? '') !== '' ? "\n".$e['extra'] : ''), $entries));
	echo TemplateUtil::render("admin/logs", [
		"units" => $sources, "game_id" => $game, "from" => $from, "to" => $to, "refresh" => !empty($_GET["refresh"]),
		"unit" => $unit,
		"lines" => $lines,
		"level" => $level,
		"raw" => $raw,
		"text" => $text,
		"entries" => $entries,
		"is_file" => $unit === SITE || $unit === ACTIVITY,
		"is_activity" => $unit === ACTIVITY,
		"group" => $group,
		"available" => ($unit === SITE || $unit === ACTIVITY) ? $text !== null : $control->canReadJournal(),
		// Two different capabilities, asked separately: reading the journal
		// needs only group membership, restarting needs the helper.
		"web_user" => function_exists("posix_getpwuid") && function_exists("posix_geteuid")
			? (posix_getpwuid(posix_geteuid())["name"] ?? "?")
			: "?",
	]);
