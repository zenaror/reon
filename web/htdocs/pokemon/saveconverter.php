<?php
require_once dirname(__DIR__,2).'/classes/GameSupportUtil.php';
if (!GameSupportUtil::enabled('crystal')) { http_response_code(503); exit; }

	require_once("../../classes/TemplateUtil.php");
	session_start();

	echo TemplateUtil::render("/pokemon/saveconverter", [

	]);
