<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/MailUtil.php");
	require_once("../../classes/RelayUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/UserUtil.php");
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
		$stmt = $db->prepare("select email, username, dion_ppp_id, dion_email_local, log_in_password, money_spent, trade_region_allowlist, custom_pokemon_news_opt_in, timezone from sys_users where id = ?");
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
            "errors" => $errors
		]);
	} else {
		header("Location: /index.php");
	}
