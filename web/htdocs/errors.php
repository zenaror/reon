<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/SessionUtil.php");
	require_once("../classes/ServiceStatusUtil.php");
	require_once("../classes/AdapterErrorUtil.php");
	session_start();

	// Os códigos vêm de web/data/adapter_errors.json (gerado) e a explicação
	// de adapter_error_notes.<idioma>.json (escrita à mão); AdapterErrorUtil
	// junta os dois no idioma em que a pessoa está lendo.
	echo TemplateUtil::render("errors", [
		"nav_item" => "guide",
		"errors" => AdapterErrorUtil::rows(SessionUtil::getInstance()->getLocale()),
		"services" => ServiceStatusUtil::getInstance()->getAll(),
	]);
?>
