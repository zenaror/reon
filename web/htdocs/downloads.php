<?php
	require_once("../classes/PageUtil.php");
	session_start();

	// Text lives in web/pages/downloads.<locale>.md -- edit that, not this.
	PageUtil::show("downloads", "downloads");
?>
