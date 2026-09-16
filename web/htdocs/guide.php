<?php
	require_once("../classes/PageUtil.php");
	session_start();

	// Text lives in web/pages/guide.<locale>.md -- edit that, not this.
	PageUtil::show("guide", "guide");
?>
