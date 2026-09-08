<?php
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/NewsUtil.php");
	session_start();

	if (!SessionUtil::getInstance()->isAdmin()) {
		http_response_code(404);
		return;
	}

	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		http_response_code(400);
		return;
	}
	CsrfUtil::check();

	// Rendered through the very same converter the public page uses, so the
	// preview can't disagree with what readers will actually get.
	header("Content-Type: text/html; charset=utf-8");
	echo NewsUtil::getInstance()->render(isset($_POST["body"]) ? $_POST["body"] : "");
