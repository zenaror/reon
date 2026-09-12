<?php
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DeviceAuthUtil.php");
	session_start();

	if (SessionUtil::getInstance()->isSessionActive()) {
		DeviceAuthUtil::getInstance()->revokeAllDevices($_SESSION["user_id"]);
		echo TemplateUtil::render("/user/revoke_devices");
	} else {
		header("Location: /index.php");
	}
