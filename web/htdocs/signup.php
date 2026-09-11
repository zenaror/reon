<?php
	require_once("../classes/SessionUtil.php");
	require_once("../classes/CsrfUtil.php");
	require_once("../classes/UserUtil.php");
	require_once("../classes/PageUtil.php");
	session_start();

	// Os dois documentos que o checkbox de consentimento cita, prontos para o
	// modal. Vêm do MESMO Markdown que /terms.php e /privacy.php servem, então
	// não há segunda cópia do texto para divergir, e vêm embutidos na página:
	// é o documento que o consentimento referencia, e ele tem de estar legível
	// ali mesmo, sem depender de uma segunda requisição dar certo.
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