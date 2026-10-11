<?php
	// What people DO on the service, one JSON line per event, in
	// /var/log/reon/activity.log: sign-ups, logins (web and game), password
	// and e-mail changes, account deletion, what the game downloads and
	// uploads, and trades (the Node jobs write to the same file through
	// lib/activity.js). The admin panel's Logs page reads it as "Activity".
	//
	//   ActivityLog::record("login", ["account" => 12], "info");
	//   -> {"ts":"2026-09-29T13:30:00Z","level":"info","component":"activity",
	//       "event":"login","msg":"login","account":12}
	//
	// What goes in, on purpose: the numeric account id, the event and the game
	// path. What stays out: the IP address (the web server's own log already
	// holds it, with the same time, for the same 14 days -- repeating it here
	// would only copy personal data), e-mail addresses, passwords and hashes, anything the
	// person typed (a failed login does NOT record the name that was tried:
	// people paste passwords into that box), message text, Pokémon nicknames.
	// Kept 14 days by /etc/logrotate.d/reon, like the other connection logs.
	require_once(__DIR__."/LogUtil.php");

	class ActivityLog {

		const FILE = "/var/log/reon/activity.log";

		// $level: "info" for things that happened, "warn" for a refusal
		// (failed login, failed game auth) so the level filter finds them.
		public static function record($event, array $fields = [], $level = "info") {
			if (isset($GLOBALS['game_request_id']) && in_array($event, ['game-login','game-login-failed','download','upload','ranking','utility'], true)) {
				$fields['request_id'] = $GLOBALS['game_request_id'];
			}
			if (!isset($fields['game_id']) && in_array($event, ['game-login','game-login-failed'], true) && preg_match('#(?:AGB|CGB|SGB)-[A-Z0-9]{4}#i', (string)($_GET['name'] ?? ''), $match)) $fields['game_id'] = strtoupper($match[0]);
            $line = ["ts" => gmdate("Y-m-d\\TH:i:s\\Z"), "level" => $level, "component" => "activity",
			         "event" => (string)$event, "msg" => (string)$event];
			foreach ($fields as $k => $v) {
				if ($v === null || $k === "ts" || $k === "level" || $k === "component" || $k === "event" || $k === "msg") continue;
				// One line per event, whatever a caller passes.
				$line[$k] = is_string($v) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u', " ", $v), 0, 160) : $v;
			}
			$json = json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
			if ($json === false) return;
			// Logging must never break the request it describes.
			if (@file_put_contents(self::FILE, $json."\n", FILE_APPEND | LOCK_EX) === false) {
				LogUtil::warn("activity", "could not write ".self::FILE.": ".$json);
			}
		}

		// A request from the game (download, upload, ranking). Recorded when the
		// request ENDS, so the outcome (200, 401, 404...) and the account the
		// authentication established are known. Scripts exit() in the middle of
		// serving; shutdown functions still run.
		public static function gameRequest($type, $path) {
			static $registered = [];
            $key = (string)$type.':'.(string)$path;
            if (isset($registered[$key])) return;
            $registered[$key] = true;
			if (!isset($GLOBALS['game_request_id'])) $GLOBALS['game_request_id'] = bin2hex(random_bytes(8));
            $path = (string)$path;
			register_shutdown_function(function () use ($type, $path) {
				$status = http_response_code();
                $challenge = $status === 401 && count(array_filter(headers_list(), fn($h) => stripos($h, "WWW-Authenticate:") === 0)) > 0;
				$account = (isset($_SESSION["type"]) && $_SESSION["type"] === "cgb" && isset($_SESSION["userId"]))
					? (int)$_SESSION["userId"]
					: (isset($_SESSION["utility_authed_user_id"]) ? (int)$_SESSION["utility_authed_user_id"] : null);
				self::record($type, [
                    ...($GLOBALS['game_upload_metadata'] ?? []),
					...($GLOBALS['game_auth_metadata'] ?? []),
					"path" => $path,
                    "game_id" => preg_match('#(?:AGB|CGB|SGB)-[A-Z0-9]{4}#i', $path, $m) ? strtoupper($m[0]) : null,
                    "method" => $_SERVER['REQUEST_METHOD'] ?? null,
                    "bytes_in" => isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null,
                    "reason" => $GLOBALS['game_refusal_reason'] ?? ($challenge ? "auth-challenge" : null),
					"status" => $status === false ? 200 : $status,
					"account" => $account,
				], ($status !== false && $status >= 400 && !$challenge) ? "warn" : "info");
			});
		}
	}
