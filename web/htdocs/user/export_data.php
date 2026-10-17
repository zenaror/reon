<?php
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AccountDataUtil.php");
	session_start();

	// Export the authenticated account data as one file. GET is read-only; another origin can
	// trigger a download but cannot read its contents under the same-origin policy.
	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /index.php");
		exit;
	}

	$dados = AccountDataUtil::getInstance()->export($_SESSION["user_id"]);
	if ($dados === null) {
		http_response_code(404);
		exit;
	}

	// Use JSON_UNESCAPED_UNICODE for readable Japanese and JSON_INVALID_UTF8_SUBSTITUTE so
	// binary game-mail bodies cannot make the entire export silently fail.
	$json = json_encode(
		$dados,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
	);
	if ($json === false) {
		http_response_code(500);
		exit;
	}

	$nome = "reon-" . preg_replace('/[^A-Za-z0-9_.-]/', "", (string)$dados["conta"]["username"])
		. "-" . gmdate("Y-m-d") . ".json";

	header("Content-Type: application/json; charset=utf-8");
	header('Content-Disposition: attachment; filename="' . $nome . '"');
	header("Content-Length: " . strlen($json));
	// The export includes private mailbox contents; forbid proxy and browser-disk caching.
	header("Cache-Control: no-store");

	print $json;
