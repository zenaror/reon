<?php
	require_once("../classes/PageUtil.php");
	session_start();

	// Text lives in web/pages/terms.<locale>.md -- edit that, not this.
	PageUtil::show("terms", "terms");
?>
