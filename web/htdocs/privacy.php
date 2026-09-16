<?php
	require_once("../classes/PageUtil.php");
	session_start();

	// Text lives in web/pages/privacy.<locale>.md -- edit that, not this.
	PageUtil::show("privacy", "privacy");
?>
