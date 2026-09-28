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

			// The rankings box, and it is genuinely optional: it does not
			// go into the isset() above, because an unchecked box is not
			// sent by the browser -- if it did, unchecking it would fail
			// sign-up with a 400 and nobody would understand why.
			$rankingsOptIn = isset($_POST["rankingsOptIn"]) ? 1 : 0;

			// Date of birth, optional. Also outside the required isset()
			// above, for the same reason as the box: a field the person
			// does not have to fill in cannot fail sign-up.
			$birthDate = (string)($_POST["birthDate"] ?? "");
			// Normalised here only to give the form back what was typed
			// without propagating garbage; what decides what gets stored is
			// createUser.
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
				// Only returns what is actually a date; if the person typed
				// something that is not one, the field comes back empty
				// instead of repeating their error.
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
					// The box starts at the same default the column uses.
					// They are the same value on purpose: if the default
					// ever flips to off, the sign-up screen follows along
					// with nobody having to remember it.
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
