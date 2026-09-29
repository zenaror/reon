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
        // Same shape as the Pokémon News opt-in just above -- Mobile
        // Stadium content is entirely fan-made/reconstructed too, so it
        // needs the same explicit per-account consent (owner's request,
        // 2026-09-28).
        if (array_key_exists("mobileStadiumCustomOptIn", $_POST)) {
            if (in_array($_POST["mobileStadiumCustomOptIn"], array("0", "1"), true)) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set custom_mobile_stadium_opt_in = ? where id = ?");
                $opt_in = intval($_POST["mobileStadiumCustomOptIn"]);
                $stmt->bind_param("ii", $opt_in, $_SESSION["user_id"]);
                $stmt->execute();
            } else {
                $errors[] = "mobileStadiumValue";
            }
        }
        // Same shape again for Game Boy Wars 3: custom maps (ids 2000-9999)
        // are only listed to accounts that opted in (owner's request,
        // 2026-09-28).
        if (array_key_exists("gbwarsCustomOptIn", $_POST)) {
            if (in_array($_POST["gbwarsCustomOptIn"], array("0", "1"), true)) {
                $db = DBUtil::getInstance()->getDB();
                $stmt = $db->prepare("update sys_users set custom_gbwars_opt_in = ? where id = ?");
                $opt_in = intval($_POST["gbwarsCustomOptIn"]);
                $stmt->bind_param("ii", $opt_in, $_SESSION["user_id"]);
                $stmt->execute();
            } else {
                $errors[] = "gbwarsValue";
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

            // "Baixar" é o mesmo envio, com um botão a mais: grava e só então
            // entrega o arquivo. É por isso que ele é submit e não link -- um
            // link baixaria o que está GRAVADO, e quem acabou de trocar a cor
            // sem salvar receberia uma bin que não corresponde à tela.
            //
            // Só redireciona se a gravação passou. Com valor recusado a pessoa
            // volta para a página com o erro, em vez de receber calada um
            // arquivo com o valor antigo.
            if (!$errors && array_key_exists("downloadAfterSave", $_POST)) {
                header("Location: /user/adapter_config.php");
                exit;
            }
        }
        // The date of birth: the person can provide it here if they did not
        // at sign-up, and after that it is FIXED (owner's decision,
        // 2026-09-28). It is the age gate for the public rankings, and a
        // gate the person can reopen by editing the date is not a gate --
        // someone under 13 would only have to type another year. The stored
        // value is checked HERE, not only by the template hiding the field:
        // a POST made by hand is the obvious way around a control that is
        // merely absent. Once set, whatever arrives is ignored in silence,
        // like the adapter fields when the panel turns them off; the
        // update itself also refuses a row that already has a date, so two
        // racing requests cannot overwrite each other. What stays possible
        // is deleting the account, which removes it with everything else.
        if (array_key_exists("birthDate", $_POST)) {
            $db = DBUtil::getInstance()->getDB();
            $stmt = $db->prepare("select birth_date from sys_users where id = ? limit 1");
            $stmt->bind_param("i", $_SESSION["user_id"]);
            $stmt->execute();
            $guardada = $stmt->get_result()->fetch_assoc()["birth_date"] ?? null;
            $jaTemData = ($guardada !== null && $guardada !== "" && $guardada !== "0000-00-00");
            if (!$jaTemData) {
                $nasc = UserUtil::normalizeBirthDate($_POST["birthDate"]);
                if ($nasc === null) {
                    $errors[] = "birthDateValue";
                } elseif ($nasc !== "") {
                    $stmt = $db->prepare("update sys_users set birth_date = ? where id = ? and birth_date is null");
                    $stmt->bind_param("si", $nasc, $_SESSION["user_id"]);
                    $stmt->execute();
                }
            }
        }

        // Rankings: opt in or out. The data keeps being received and
        // stored -- what this preference governs is PUBLICATION, which is
        // the open page and the table the game shows other players.
        //
        // Turning it off deletes nothing, and that is deliberate: someone
        // who turns it off today and back on tomorrow gets their history
        // back, instead of discovering the choice cost them what they
        // already had. Whoever wants the data gone has the delete-account
        // button, which genuinely deletes.
        if (array_key_exists("rankingsOptIn", $_POST)) {
            // The age rules, and it is checked HERE, not only at account
            // creation: a direct POST to this screen is the obvious way to
            // bypass a check that only ran at sign-up. Someone blocked
            // does not get to turn it on -- and gets no error either,
            // because the control already arrives disabled; a POST like
            // this is made by hand.
            $bloqueado = UserUtil::rankingsBlockedByAge($_SESSION["user_id"]) === true;
            $db = DBUtil::getInstance()->getDB();
            $stmt = $db->prepare("update sys_users set rankings_opt_in = ? where id = ?");
            $opt = (!$bloqueado && ($_POST["rankingsOptIn"] ?? "") === "1") ? 1 : 0;
            $stmt->bind_param("ii", $opt, $_SESSION["user_id"]);
            $stmt->execute();
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
		$stmt = $db->prepare("select email, username, dion_ppp_id, dion_email_local, log_in_password, money_spent, trade_region_allowlist, custom_pokemon_news_opt_in, custom_mobile_stadium_opt_in, custom_gbwars_opt_in, timezone, adapter_device, adapter_unmetered, rankings_opt_in, birth_date from sys_users where id = ?");
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
			// 8-character one the games are limited to (see docs/OPERATIONS.md and mail/gameFormat.js).
			"external_email" => $result["username"]."@".ConfigUtil::getInstance()->getConfig()["email_domain"],
			"external_email_short" => $result["dion_email_local"]."@".ConfigUtil::getInstance()->getConfig()["email_domain"],
			"log_in_password" => $result["log_in_password"],
			"money_spent" => $result["money_spent"],
            "trade_region_allowlist" => $result["trade_region_allowlist"],
            "pokemon_news_custom_opt_in" => intval($result["custom_pokemon_news_opt_in"]),
            "mobile_stadium_custom_opt_in" => intval($result["custom_mobile_stadium_opt_in"]),
            "gbwars_custom_opt_in" => intval($result["custom_gbwars_opt_in"]),
            "game_tab" => in_array((string)($_POST["gameTab"] ?? ""), array("crystal", "gbwars"), true) ? (string)$_POST["gameTab"] : "crystal",
            "birth_date_locked" => (($result["birth_date"] ?? "") !== "" && $result["birth_date"] !== "0000-00-00"),
            "time_zone" => $result["timezone"],
            "all_time_zones" => timezone_identifiers_list(),
			"relay_token" => $relay !== null ? bin2hex($relay["token"]) : null,
			"relay_number" => $relay !== null ? $relay["number"] : null,
			"inbox_size" => $inbox_size,
			// O cartão do adaptador só existe quando o painel libera. Nulo
			// na conta quer dizer "não escolhi": o formulário abre no que o
			// painel está mandando hoje, e é isso que a pessoa recebe se
			// nunca salvar.
			"rankings_opt_in" => ((int)$result["rankings_opt_in"] === 1),
			"birth_date" => ($result["birth_date"] ?? ""),
			// true only when there is a date AND it says under 13. With no
			// date it is null, and the page treats null as "may choose".
			"rankings_blocked" => (UserUtil::rankingsBlockedByAge($_SESSION["user_id"]) === true),
			"rankings_min_age" => UserUtil::RANKINGS_MIN_AGE,
			"adapter_choice_allowed" => SettingsUtil::getInstance()->getValid("bin_user_choice") === "1",
			"adapter_device" => $result["adapter_device"] !== null
				? (string)(int)$result["adapter_device"]
				: SettingsUtil::getInstance()->getValid("bin_adapter_device"),
			"adapter_unmetered" => $result["adapter_unmetered"] !== null
				? ((int)$result["adapter_unmetered"] === 1)
				: (SettingsUtil::getInstance()->getValid("bin_unmetered") === "1"),
			"adapter_is_default" => $result["adapter_device"] === null,
			// Sem o verde (10) por decisão do dono. O buraco no meio da
			// sequência é de propósito: o número é o enum da libmobile, não
			// uma posição de lista, então preencher a lacuna renomearia um
			// adaptador em vez de arrumar a numeração.
			"adapter_models" => [8 => "Blue", 9 => "Yellow", 11 => "Red"],
            "errors" => $errors
		]);
	} else {
		header("Location: /index.php");
	}
