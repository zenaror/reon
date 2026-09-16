<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/MailUtil.php");
	require_once("../../classes/RelayUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/UserUtil.php");
	require_once("../../classes/SettingsUtil.php");
	session_start();

	if (SessionUtil::getInstance()->isSessionActive()) {
		$db_util = DBUtil::getInstance();

		// This page never gated on the method: it just looks for fields in
		// $_POST, which is empty on a GET. The token check has to sit before
		// the first of those lookups, and only when there is a POST to check.
		if ($_SERVER["REQUEST_METHOD"] === "POST") CsrfUtil::check();

        $errors = array(); //~To contain multiple problems at once
        //~Update user settings if needed, before preparing to render the page
        if (array_key_exists("tradeRegions",$_POST)) {
            //~Validated by its format, not against a list of three literals:
            //~the pool format allows more than the menu offers, and one of the
            //~values already in the database ("e,fdsipuj") was not on that list
            //~-- so that account could not have saved this form at all.
            $regions = UserUtil::normalizeTradeRegions($_POST["tradeRegions"]);
            if ($regions !== null) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set trade_region_allowlist = ? where id = ?");
                $stmt->bind_param("si", $regions, $_SESSION["user_id"]);
                $stmt->execute();
            } else { //~If region setting is invalid, make no changes and issue an error
                $errors[] = "regionValue";
            }
        }
        if (array_key_exists("pokemonNewsCustomOptIn", $_POST)) {
            if (in_array($_POST["pokemonNewsCustomOptIn"], array("0", "1"), true)) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set custom_pokemon_news_opt_in = ? where id = ?");
                $opt_in = intval($_POST["pokemonNewsCustomOptIn"]);
                $stmt->bind_param("ii", $opt_in, $_SESSION["user_id"]);
                $stmt->execute();
            } else {
                $errors[] = "pokemonNewsValue";
            }
        }
        // Cor do adaptador e marca de não-tarifado, quando o painel libera.
        //
        // A checagem de `bin_user_choice` acontece AQUI, e não só no
        // template: esconder o formulário não impede um POST, e a diferença
        // importa porque estes dois valores vão parar dentro de um arquivo
        // binário que um cartucho lê. Com a opção desligada, o que vier no
        // POST é ignorado em silêncio -- não é erro da pessoa, é campo que
        // não existe mais.
        if (SettingsUtil::getInstance()->getValid("bin_user_choice") === "1"
            && array_key_exists("adapterDevice", $_POST)) {
            $modelo = (string)$_POST["adapterDevice"];
            $unmetered = (($_POST["adapterUnmetered"] ?? "") === "1") ? 1 : 0;
            // Mesma regra que o painel usa, pela mesma função: o conjunto de
            // modelos válidos é o enum da libmobile, e ele não pode divergir
            // entre as duas telas que escrevem no mesmo byte.
            if (SettingsUtil::isValid("bin_adapter_device", $modelo)) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set adapter_device = ?, adapter_unmetered = ? where id = ?");
                $dev = (int)$modelo;
                $stmt->bind_param("iii", $dev, $unmetered, $_SESSION["user_id"]);
                $stmt->execute();
            } else {
                $errors[] = "adapterValue";
            }
        }
        if (array_key_exists("timeZone", $_POST)) {
            // Identifiers only; the default is Asia/Tokyo (the game's own
            // time zone). "+0900" was the old spelling of that default.
            if (in_array($_POST["timeZone"], timezone_identifiers_list(), true)) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set timezone = ? where id = ?");
                $stmt->bind_param("si", $_POST["timeZone"], $_SESSION["user_id"]);
                $stmt->execute();
            } else {
                $errors[] = "timeZoneValue";
            }
        }


		
		$db = $db_util->getDB();
		$stmt = $db->prepare("select email, username, dion_ppp_id, dion_email_local, log_in_password, money_spent, trade_region_allowlist, custom_pokemon_news_opt_in, timezone, adapter_device, adapter_unmetered from sys_users where id = ?");
		$stmt->bind_param("i", $_SESSION["user_id"]);
		$stmt->execute();
		$result = DBUtil::fancy_get_result($stmt)[0];
		
		// A mesma contagem que o webmail mostra -- vinda do Dovecot, e já sem
		// a correspondência dos jogos, que não aparece em lista nenhuma.
		$inbox_size = MailUtil::getInstance()->countForUser($_SESSION["user_id"]);

		$relay = RelayUtil::getInstance()->getForUser($_SESSION["user_id"]);

		echo TemplateUtil::render("/user/summary", [
			"email" => $result["email"],
			"dion_ppp_id" => $result["dion_ppp_id"],
			"dion_email" => $result["dion_email_local"]."@".ConfigUtil::getInstance()->getConfig()["email_domain_dion"],
			"username" => $result["username"],
			// Both forms reach the same inbox: the full name, and the
			// 8-character one the games are limited to (see deliver.js).
			"external_email" => $result["username"]."@".ConfigUtil::getInstance()->getConfig()["email_domain"],
			"external_email_short" => $result["dion_email_local"]."@".ConfigUtil::getInstance()->getConfig()["email_domain"],
			"log_in_password" => $result["log_in_password"],
			"money_spent" => $result["money_spent"],
            "trade_region_allowlist" => $result["trade_region_allowlist"],
            "pokemon_news_custom_opt_in" => intval($result["custom_pokemon_news_opt_in"]),
            "time_zone" => $result["timezone"],
            "all_time_zones" => timezone_identifiers_list(),
			"relay_token" => $relay !== null ? bin2hex($relay["token"]) : null,
			"relay_number" => $relay !== null ? $relay["number"] : null,
			"inbox_size" => $inbox_size,
			// O cartão do adaptador só existe quando o painel libera. Nulo
			// na conta quer dizer "não escolhi": o formulário abre no que o
			// painel está mandando hoje, e é isso que a pessoa recebe se
			// nunca salvar.
			"adapter_choice_allowed" => SettingsUtil::getInstance()->getValid("bin_user_choice") === "1",
			"adapter_device" => $result["adapter_device"] !== null
				? (string)(int)$result["adapter_device"]
				: SettingsUtil::getInstance()->getValid("bin_adapter_device"),
			"adapter_unmetered" => $result["adapter_unmetered"] !== null
				? ((int)$result["adapter_unmetered"] === 1)
				: (SettingsUtil::getInstance()->getValid("bin_unmetered") === "1"),
			"adapter_is_default" => $result["adapter_device"] === null,
			"adapter_models" => [8 => "Blue", 9 => "Yellow", 10 => "Green", 11 => "Red"],
            "errors" => $errors
		]);
	} else {
		header("Location: /index.php");
	}
