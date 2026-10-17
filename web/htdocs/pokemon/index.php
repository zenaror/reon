<?php
require_once dirname(__DIR__,2).'/classes/GameSupportUtil.php';
if (!GameSupportUtil::enabled('crystal')) { http_response_code(503); exit; }

	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/PageUtil.php");
	session_start();

	// Text sections come from web/pages/games/pokemon.<locale>.md and
	// pokemon-stadium.<locale>.md. Without the Stadium document, fall back to a single page
	// without tabs.
	echo TemplateUtil::render("pokemon/index", [
		"doc_html" => PageUtil::html("games/pokemon"),
		"stadium_html" => PageUtil::html("games/pokemon-stadium"),
	]);
?>
