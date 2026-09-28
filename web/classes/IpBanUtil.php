<?php
	require_once(__DIR__ . "/DBUtil.php");

	// The admin panel's list of banned addresses, and the way to add and lift
	// a ban by hand.
	//
	// fail2ban does the banning, and only root can talk to it, so -- like the
	// Services page -- everything privileged goes through one small helper the
	// server has to be told to allow (setup-script/5-admin-control.sh installs
	// /usr/local/sbin/reon-ban-ctl and its single sudoers entry). The helper
	// accepts three verbs (list, ban, unban), checks that the address is an IP
	// and, for a ban, that it is public; the jail for a manual ban is fixed
	// inside it. The web app cannot ask for anything else.
	//
	// fail2ban records no reason for a manual ban, so the reason the
	// administrator wrote is kept here (sys_ip_bans). A ban always has one.
	//
	// When the helper is not installed nothing is attempted and the panel says
	// so, exactly as the Services page does.
	class IpBanUtil {

		private static $instance;
		private static $allowed = null;

		const HELPER = "/usr/local/sbin/reon-ban-ctl";
		const MANUAL_JAIL = "reon-manual";
		const REASON_MIN = 3;
		const REASON_MAX = 300;

		public static function getInstance() {
			if (!isset(self::$instance)) self::$instance = new IpBanUtil();
			return self::$instance;
		}

		// The helper existing is not the same as being allowed to call it:
		// `sudo -n -l` answers whether the rule exists for whoever asks,
		// without running anything or asking for a password.
		public function available() {
			if (self::$allowed !== null) return self::$allowed;
			if (!is_file(self::HELPER) || !is_executable(self::HELPER)) return self::$allowed = false;
			$out = $this->run(["/usr/bin/sudo", "-n", "-l", self::HELPER]);
			return self::$allowed = ($out["code"] === 0);
		}

		// Current bans, each with the reason we know for it. Returns
		// [rows, error] where error is "" or a message.
		public function current() {
			if (!$this->available()) return [[], "unavailable"];
			$out = $this->run(["/usr/bin/sudo", "-n", self::HELPER, "list"]);
			if ($out["code"] !== 0) return [[], trim($out["stderr"]) ?: "failed"];
			$rows = json_decode($out["stdout"], true);
			if (!is_array($rows)) return [[], "unreadable answer from the helper"];

			$db = DBUtil::getInstance()->getDB();
			foreach ($rows as &$r) {
				$r["reason"] = null;
				$r["by"] = null;
				$stmt = $db->prepare(
					"select b.reason, b.banned_at, u.username as by_name
					   from sys_ip_bans b left join sys_users u on u.id = b.banned_by
					  where b.ip = ? and b.unbanned_at is null
					  order by b.id desc limit 1");
				$stmt->bind_param("s", $r["ip"]);
				$stmt->execute();
				$rec = $stmt->get_result()->fetch_assoc();
				if ($rec) {
					$r["reason"] = $rec["reason"];
					$r["by"] = $rec["by_name"];
				}
				$r["manual"] = ($r["reason"] !== null) || $r["jail"] === self::MANUAL_JAIL;
			}
			unset($r);
			usort($rows, fn($a, $b) => strcmp($b["since"], $a["since"]));
			return [$rows, ""];
		}

		// Returns [ok, errorKey, detail]. errorKey maps to admin.err-<key>.
		public function ban($ip, $reason, $adminId, $callerIp, $serverIp) {
			$ip = trim((string)$ip);
			$reason = trim((string)$reason);
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				return [false, "ban-bad-ip", ""];
			}
			if (mb_strlen($reason) < self::REASON_MIN) return [false, "ban-reason-required", ""];
			if (mb_strlen($reason) > self::REASON_MAX) return [false, "ban-reason-long", ""];
			// Two addresses a mistyped ban must never take out: the one the
			// administrator is using right now, and the server itself.
			if ($ip === (string)$callerIp) return [false, "ban-self", ""];
			if ($serverIp !== "" && $ip === (string)$serverIp) return [false, "ban-self", ""];
			if (!$this->available()) return [false, "ban-unavailable", ""];

			$out = $this->run(["/usr/bin/sudo", "-n", self::HELPER, "ban", $ip]);
			if ($out["code"] !== 0) return [false, "ban-failed", trim($out["stderr"])];

			$db = DBUtil::getInstance()->getDB();
			$jail = self::MANUAL_JAIL;
			$by = (int)$adminId;
			$stmt = $db->prepare("insert into sys_ip_bans (ip, jail, reason, banned_by) values (?, ?, ?, ?)");
			$stmt->bind_param("sssi", $ip, $jail, $reason, $by);
			$stmt->execute();
			return [true, "", ""];
		}

		// Lifts the ban from every jail. Returns [ok, errorKey, detail].
		public function unban($ip, $adminId, $note) {
			$ip = trim((string)$ip);
			if (filter_var($ip, FILTER_VALIDATE_IP) === false) return [false, "ban-bad-ip", ""];
			if (!$this->available()) return [false, "ban-unavailable", ""];

			$out = $this->run(["/usr/bin/sudo", "-n", self::HELPER, "unban", $ip]);
			if ($out["code"] !== 0) return [false, "ban-failed", trim($out["stderr"])];

			$db = DBUtil::getInstance()->getDB();
			$by = (int)$adminId;
			$note = mb_substr(trim((string)$note), 0, self::REASON_MAX);
			$n = $note === "" ? null : $note;
			$stmt = $db->prepare("update sys_ip_bans set unbanned_at = now(), unbanned_by = ?, unban_note = ? where ip = ? and unbanned_at is null");
			$stmt->bind_param("iss", $by, $n, $ip);
			$stmt->execute();
			return [true, "", ""];
		}

		private function run(array $cmd) {
			$spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return ["code" => 127, "stdout" => "", "stderr" => "could not run"];
			fclose($pipes[0]);
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$code = proc_close($proc);
			return ["code" => $code, "stdout" => (string)$stdout, "stderr" => (string)$stderr];
		}
	}
