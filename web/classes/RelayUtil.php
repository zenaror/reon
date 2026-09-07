<?php
	require_once("DBUtil.php");

	// Provisions the mobile-relay peer-matching token+number at account
	// creation time, instead of the device negotiating it live on first
	// connection. relay_users (in mobile-relay's own separate "mobile"
	// MySQL database, credentials in mobile-relay/config.ini -- a sibling
	// directory of this checkout) is the only place this is stored -- no
	// copy is kept in sys_users, so there's nothing to drift out of sync.
	//
	// The relay is optional: mobile-relay might not be installed, its
	// config.ini might not exist or might be set up for sqlite instead of
	// mysql, or its database might just be unreachable. None of that is
	// this class's caller's problem -- every public method here fails soft
	// (returns null / does nothing) instead of throwing, so account
	// creation, the config.bin download, and the account page all keep
	// working with the relay fields simply absent.
	class RelayUtil {

		private static $instance;
		private $relayDb;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new RelayUtil();
			}
			return self::$instance;
		}

		public function getRelayDB() {
			if (!isset($this->relayDb)) {
				$this->relayDb = self::connectRelayDB();
			}
			return $this->relayDb;
		}

		// Returns a connected mysqli, or false if the relay isn't
		// reachable/configured for any reason at all.
		private function connectRelayDB() {
			$iniPath = dirname(__DIR__, 2)."/../mobile-relay/config.ini";
			if (!file_exists($iniPath)) return false;

			// Not parse_ini_file(): mobile-relay's own config.ini carries
			// an unquoted MySQL password that can contain "!"/"&"/etc,
			// which PHP's ini parser (unlike Python's configparser, which
			// mobile-relay itself uses to read the very same file) rejects
			// as a syntax error. Read "key = value" lines under [mysql]
			// manually instead, taking the value verbatim after the first
			// "=" so none of that is special.
			$mysql = [];
			$inSection = false;
			foreach (file($iniPath, FILE_IGNORE_NEW_LINES) as $line) {
				$line = trim($line);
				if ($line === "" || $line[0] === "#" || $line[0] === ";") continue;
				if ($line[0] === "[") {
					$inSection = (strtolower(trim($line, "[]")) === "mysql");
					continue;
				}
				if (!$inSection) continue;
				$eq = strpos($line, "=");
				if ($eq === false) continue;
				$mysql[trim(substr($line, 0, $eq))] = trim(substr($line, $eq + 1));
			}
			if (empty($mysql)) return false;

			// mysqli throws on connection failure by default (PHP 8.1+).
			try {
				$db = mysqli_init();
				if (isset($mysql["unix_socket"]) && $mysql["unix_socket"] !== "") {
					$db->real_connect(null, $mysql["user"], $mysql["passwd"], $mysql["db"], null, $mysql["unix_socket"]);
				} else {
					$db->real_connect($mysql["host"], $mysql["user"], $mysql["passwd"], $mysql["db"]);
				}
				return $db;
			} catch (\mysqli_sql_exception $e) {
				return false;
			}
		}

		private function generateToken($db) {
			for ($i = 0; $i < 10; $i++) {
				$token = random_bytes(16);
				$stmt = $db->prepare("select 1 from relay_users where token = ?");
				$stmt->bind_param("s", $token);
				$stmt->execute();
				if ($stmt->get_result()->num_rows === 0) return $token;
			}
			return null;
		}

		private function generateNumber($db) {
			for ($i = 0; $i < 10; $i++) {
				$number = "0".str_pad((string) random_int(0, 999999999), 9, "0", STR_PAD_LEFT);
				if (str_starts_with($number, "00") || str_starts_with($number, "010")) continue;
				$stmt = $db->prepare("select 1 from relay_users where number = ?");
				$stmt->bind_param("s", $number);
				$stmt->execute();
				if ($stmt->get_result()->num_rows === 0) return $number;
			}
			return null;
		}

		// Called once, right after a new sys_users row is created.
		public function provisionForUser($userId) {
			$relayDb = self::getRelayDB();
			if ($relayDb === false) return;

			try {
				$token = self::generateToken($relayDb);
				$number = self::generateNumber($relayDb);
				if ($token === null || $number === null) return;

				$stmt = $relayDb->prepare("insert into relay_users (token, number, user_id) values (?, ?, ?)");
				$stmt->bind_param("ssi", $token, $number, $userId);
				$stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				// e.g. relay_users doesn't exist because mobile-relay was
				// never actually run to create its schema -- leave the
				// account without a relay token, same as if this whole
				// class were absent.
				return;
			}
		}

		// Returns ["token" => <16 raw bytes>, "number" => <string>], or
		// null if this account has no relay token (predates this feature,
		// or mobile-relay wasn't reachable when it was created, or isn't
		// reachable right now).
		public function getForUser($userId) {
			$relayDb = self::getRelayDB();
			if ($relayDb === false) return null;

			try {
				$stmt = $relayDb->prepare("select token, number from relay_users where user_id = ?");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				$result = $stmt->get_result()->fetch_assoc();
			} catch (\mysqli_sql_exception $e) {
				return null;
			}
			if ($result === null) return null;

			return ["token" => $result["token"], "number" => $result["number"]];
		}
	}
?>
