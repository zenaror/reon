<?php
	require_once("../classes/SessionUtil.php");
	require_once("../classes/CsrfUtil.php");
	require_once("../classes/UserUtil.php");
	require_once("../classes/ConfigUtil.php");
	session_start();

	$config = ConfigUtil::getInstance()->getConfig();
	
	if ($_SERVER["REQUEST_METHOD"] == "POST") {
		CsrfUtil::check();
		if (isset($_POST["id"]) && isset($_POST["key"]) && isset($_POST["reonEmail"]) && isset($_POST["password"]) && isset($_POST["passwordConfirm"]) && isset($_POST["tradeRegions"])) {
			// Fetch email before completing signup (completeSignupAction deletes sys_signup).
			$email = UserUtil::getInstance()->verifySignupRequest($_POST["id"], $_POST["key"]);

			$optIn = 0;
			if (isset($_POST["pokemonNewsCustomOptIn"])) {
				$optIn = ($_POST["pokemonNewsCustomOptIn"] == "1") ? 1 : 0;
			}

			// A caixa dos rankings, e ela é opcional de verdade: não entra no
			// isset() acima, porque caixa desmarcada não é enviada pelo
			// navegador -- se entrasse, desmarcar reprovaria o cadastro com
			// um 400 e ninguém entenderia por quê.
			$rankingsOptIn = isset($_POST["rankingsOptIn"]) ? 1 : 0;

			// Data de nascimento, opcional. Também fora do isset() obrigatório
			// acima, pelo mesmo motivo da caixa: campo que a pessoa não precisa
			// preencher não pode reprovar o cadastro.
			$birthDate = (string)($_POST["birthDate"] ?? "");
			// Normaliza aqui só para devolver ao formulário o que foi digitado
			// sem propagar lixo; quem decide o que grava é o createUser.
			$birthDateOk = UserUtil::normalizeBirthDate($birthDate);

			$result = UserUtil::getInstance()->completeSignupAction(
				$_POST["id"],
				$_POST["key"],
				$_POST["reonEmail"],
				$_POST["password"],
				$_POST["passwordConfirm"],
				$_POST["tradeRegions"],
				$optIn,
				$rankingsOptIn,
				$birthDate
			);
			echo TemplateUtil::render("signup_cont", [
				"result" => $result,
				"id" => $_POST["id"],
				"key" => $_POST["key"],
				"email" => $email,
				"reon_email" => $_POST["reonEmail"],
				"email_domain" => $config["email_domain"],
				"email_domain_dion" => $config["email_domain_dion"],
				"trade_regions" => $_POST["tradeRegions"],
				"pokemon_news_custom_opt_in" => $optIn,
				"rankings_opt_in" => $rankingsOptIn,
				// Só devolve o que é data; se a pessoa digitou algo que não é,
				// o campo volta vazio em vez de repetir o erro dela.
				"birth_date" => ($birthDateOk === null ? "" : $birthDateOk),
				"birth_date_invalid" => ($birthDateOk === null && trim($birthDate) !== "")
			]);
		} else {
			http_response_code(400);
		}
	} else {
		if (isset($_GET["id"]) && isset($_GET["key"])) {
			$email = UserUtil::getInstance()->verifySignupRequest($_GET["id"], $_GET["key"]);
			if (isset($email)) {
				echo TemplateUtil::render("signup_cont", [
					"result" => -1,
					"id" => $_GET["id"],
					"key" => $_GET["key"],
					"email" => $email,
					// Only pre-filled on first load; the POST branch above
					// echoes back whatever the person actually typed.
					"reon_email" => UserUtil::getInstance()->suggestUsername($email),
					"email_domain" => $config["email_domain"],
					"email_domain_dion" => $config["email_domain_dion"],
					"trade_regions" => "efdsipuj",
					"pokemon_news_custom_opt_in" => 0,
					"birth_date" => "",
					"birth_date_invalid" => false,
					// A caixa começa no mesmo padrão que a coluna usa. São o
					// mesmo valor de propósito: se o padrão virar desligado,
					// a tela de cadastro acompanha sem ninguém lembrar dela.
					"rankings_opt_in" => UserUtil::RANKINGS_OPT_IN_DEFAULT ? 1 : 0
				]);
			} else {
				echo TemplateUtil::render("signup_cont", [
					"result" => 1
				]);
			}
		} else {
			echo TemplateUtil::render("signup_cont", [
				"result" => 1
			]);
		}
	}
