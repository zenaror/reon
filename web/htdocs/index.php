<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/ServiceStatusUtil.php");
	require_once("../classes/NewsUtil.php");
	session_start();

	$news = NewsUtil::getInstance();

	echo TemplateUtil::render("index", [
		"services" => ServiceStatusUtil::getInstance()->getAll(),
		"news" => $news->getPublished(5),
		"has_more_news" => $news->countPublished() > 5,
	]);