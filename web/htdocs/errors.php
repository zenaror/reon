<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/SessionUtil.php");
	require_once("../classes/ServiceStatusUtil.php");
	require_once("../classes/AdapterErrorUtil.php");
	session_start();

	// AdapterErrorUtil joins generated adapter_errors.json with handwritten
	// adapter_error_notes.<locale>.json for the current reader language.
	echo TemplateUtil::render("errors", [
		"nav_item" => "guide",
		"errors" => AdapterErrorUtil::rows(SessionUtil::getInstance()->getLocale()),
		"services" => ServiceStatusUtil::getInstance()->getAll(),
	]);
?>
