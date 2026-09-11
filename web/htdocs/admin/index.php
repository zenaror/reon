<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/ServiceStatusUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();

	echo TemplateUtil::render("admin/dashboard", [
		"counts" => $admin->overview(),
		"services" => ServiceStatusUtil::getInstance()->getAll(),
		"log" => $admin->recentLog(12),
	]);
