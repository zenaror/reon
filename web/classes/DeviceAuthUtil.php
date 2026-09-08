<?php
	require_once("DBUtil.php");

	// Outbound relay device authorization (game -> real internet).
	//
	// device_auth_key is generated once per account and handed to the device
	// embedded in the config.bin download (see adapter_config.php) — there is
	// no separate provisioning call. Every config.bin download also resets
	// the server-side counter to 0 (but keeps the same key): a fresh
	// download's device always starts its own counter at 0 too, so this
	// keeps both sides in sync without invalidating any OTHER device already
	// running against the same key (see revokeAllDevices() for the separate,
	// explicit action that actually rotates the key).
	class DeviceAuthUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new DeviceAuthUtil();
			}
			return self::$instance;
		}

		// Called from adapter_config.php on every config.bin download. Returns
		// the raw 32-byte key to embed (creating the row on first download).
		public function keyForDownload($userId) {
			$db = DBUtil::getInstance()->getDB();

			$stmt = $db->prepare("select device_auth_key from sys_device_authorization where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);

			if (count($result) > 0) {
				$key = $result[0]["device_auth_key"];
				$stmt = $db->prepare("update sys_device_authorization set counter = 0, authorized = 0, authorized_until = null where user_id = ?");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				return $key;
			}

			$key = random_bytes(32);
			$stmt = $db->prepare("insert into sys_device_authorization (user_id, device_auth_key, counter) values (?, ?, 0)");
			$stmt->bind_param("is", $userId, $key);
			$stmt->execute();
			return $key;
		}

		// The website's explicit "revoke all devices" action: unlike a config.bin
		// redownload, this actually rotates the key, so every device currently
		// holding the old key (there may be more than one — same account can be
		// running on an emulator and real hardware at once) needs to redownload
		// config.bin before it can authorize again.
		public function revokeAllDevices($userId) {
			$db = DBUtil::getInstance()->getDB();
			$key = random_bytes(32);
			$stmt = $db->prepare("
				insert into sys_device_authorization (user_id, device_auth_key, counter)
				values (?, ?, 0)
				on duplicate key update device_auth_key = values(device_auth_key), counter = 0, authorized = 0, authorized_until = null
			");
			$stmt->bind_param("is", $userId, $key);
			$stmt->execute();
		}

		public function hasDeviceAuth($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select 1 from sys_device_authorization where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}

		// GET /api/adapter/device-auth?ppp_id=...&action=authorize|deauthorize&counter=...&sig=...
		// Returns an HTTP status: 200 (accepted, idempotent on a repeated
		// counter), 400 (malformed request), 403 (unknown ppp_id / bad
		// signature / stale counter).
		public function handleRequest($pppId, $action, $counterRaw, $sig) {
			if (!preg_match('/^g[0-9]{9}$/', $pppId)) return 400;
			if ($action !== "authorize" && $action !== "deauthorize") return 400;
			if (!preg_match('/^(0|[1-9][0-9]*)$/', $counterRaw)) return 400;
			if (!preg_match('/^[0-9a-f]{64}$/', $sig)) return 400;

			$counter = (int) $counterRaw;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select a.id, a.device_auth_key, a.counter
				from sys_device_authorization a
				inner join sys_users u on u.id = a.user_id
				where u.dion_ppp_id = ?
			");
			$stmt->bind_param("s", $pppId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);
			if (count($result) === 0) return 403;

			$row = $result[0];
			$expectedSig = hash_hmac("sha256", $pppId."|".$action."|".$counterRaw, $row["device_auth_key"]);
			if (!hash_equals($expectedSig, $sig)) return 403;

			$lastCounter = (int) $row["counter"];
			if ($counter < $lastCounter) return 403; // stale/replayed counter

			// Equal counter used to return 200 and change nothing, on the
			// reading that it could only be a retransmission of the call
			// already applied. That is true of authorize and false of
			// deauthorize, and the difference is not cosmetic: a device whose
			// counter came back behind the server's -- which happens when it
			// restarts mid-batch -- sends its deauthorize on the number the
			// server already holds. The revocation was then swallowed, the
			// device stayed authorized, and the client was told 200.
			//
			// Observed in production on 2026-09-08: authorize c=201 was
			// refused as stale, mail still went out on the earlier 30-minute
			// window, and the deauthorize c=202 that should have closed it
			// landed on the stored 202 and did nothing.
			//
			// Revocation is fail-safe -- the worst a repeated one can do is
			// revoke something already revoked -- so it is honoured at equal
			// counter. Authorize is the direction that grants, and it still
			// requires a strictly greater counter.
			if ($counter === $lastCounter && $action !== "deauthorize") return 200;

			if ($action === "authorize") {
				$stmt = $db->prepare("update sys_device_authorization set counter = ?, authorized = 1, authorized_until = date_add(now(), interval 30 minute) where id = ?");
			} else {
				$stmt = $db->prepare("update sys_device_authorization set counter = ?, authorized = 0, authorized_until = null where id = ?");
			}
			$stmt->bind_param("ii", $counter, $row["id"]);
			$stmt->execute();
			return 200;
		}

		// For future use by the outbound relay itself (not built yet).
		public function isAuthorized($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select 1 from sys_device_authorization where user_id = ? and authorized = 1 and authorized_until > now()");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}
	}
?>
