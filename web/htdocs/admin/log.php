<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	session_start();

	AdminUtil::guard();

	echo TemplateUtil::render("admin/audit", [
		"log" => AdminUtil::getInstance()->recentLog(200),
	]);
