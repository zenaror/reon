<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/PageUtil.php");
	session_start();

	// Text sections live in web/pages/games/pokemon.<locale>.md; the second
	// tab in pokemon-stadium.<locale>.md. Sem o arquivo do Stadium a página
	// volta a ser de uma aba só, sem aba nenhuma desenhada.
	echo TemplateUtil::render("pokemon/index", [
		"doc_html" => PageUtil::html("games/pokemon"),
		"stadium_html" => PageUtil::html("games/pokemon-stadium"),
	]);
?>
