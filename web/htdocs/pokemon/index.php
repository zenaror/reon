<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/PageUtil.php");
	session_start();

	// Text sections live in web/pages/games/pokemon.<locale>.md.
	echo TemplateUtil::render("pokemon/index", [
		"doc_html" => PageUtil::html("games/pokemon"),
	]);
?>
