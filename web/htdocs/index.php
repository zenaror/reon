<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/ServiceStatusUtil.php");
	session_start();

	echo TemplateUtil::render("index", [
		"services" => ServiceStatusUtil::getInstance()->getAll(),
	]);