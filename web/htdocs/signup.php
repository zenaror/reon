<?php
	require_once("../classes/SessionUtil.php");
	require_once("../classes/CsrfUtil.php");
	require_once("../classes/UserUtil.php");
	require_once("../classes/PageUtil.php");
	session_start();

	// Embed the consent documents using the same Markdown served by terms.php and privacy.php.
	// This avoids divergent copies and lets users read them without depending on a second
	// request.
	$legal = [
		"terms_html" => PageUtil::html("terms"),
		"privacy_html" => PageUtil::html("privacy"),
	];
	
	if ($_SERVER["REQUEST_METHOD"] == "POST") {
		CsrfUtil::check();
		if (isset($_POST["email"])) {
			$result = UserUtil::getInstance()->sendSignupEmailAction($_POST["agree"], $_POST["email"]);
			echo TemplateUtil::render("signup", [
				"result" => $result,
				"email" => $_POST["email"]
			] + $legal);
		} else {
			http_response_code(400);
		}
	} else {
		echo TemplateUtil::render("signup", [
			"result" => -1
		] + $legal);
	}