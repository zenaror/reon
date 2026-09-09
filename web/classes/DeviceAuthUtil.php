<?php
	require_once("DBUtil.php");

	// Outbound relay device authorization (game -> real internet).
	//
	// device_auth_key is generated once per account and handed to the device
	// embedded in the config.bin download (see adapter_config.php) — there is
	// no separate provisioning call. The bin is meant to be downloaded once
	// and copied to whichever devices the account owner uses, so the key is
	// per account (sys_device_authorization) while the anti-replay counter and
	// the authorization window are per device (sys_device_counter): a device
	// identifies itself with a device id it chose on first use and keeps with
	// its own persisted state, and never disturbs another device's counter.
	// See revokeAllDevices() for the explicit action that rotates the key.
	class DeviceAuthUtil {

		// Cap on device rows per account. Rows are never pruned on their own:
		// a deleted row would let a captured request for that device be
		// replayed (the server would recreate the row at that counter). The
		// "revoke all devices" action rotates the key, which is what makes old
		// captures worthless, and clears the rows with it.
		const MAX_DEVICES_PER_ACCOUNT = 32;

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new DeviceAuthUtil();
			}
			return self::$instance;
		}

		// Called from adapter_config.php on every config.bin download. Returns
		// the raw 32-byte key to embed (creating it on first download). A
		// redownload changes nothing else: devices already running keep their
		// own counters, and a new device starts its own row from 0.
		public function keyForDownload($userId) {
			$db = DBUtil::getInstance()->getDB();

			$stmt = $db->prepare("select device_auth_key from sys_device_authorization where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);

			if (count($result) > 0) {
				return $result[0]["device_auth_key"];
			}

			$key = random_bytes(32);
			$stmt = $db->prepare("insert into sys_device_authorization (user_id, device_auth_key) values (?, ?)");
			$stmt->bind_param("is", $userId, $key);
			$stmt->execute();
			return $key;
		}

		// The website's explicit "revoke all devices" action: rotates the key,
		// so every device holding the old one (emulator and real hardware can
		// share it) has to redownload config.bin before it can authorize
		// again, and drops every device row -- with the old key gone, nothing
		// signed under it can be replayed, so the rows no longer guard anything.
		public function revokeAllDevices($userId) {
			$db = DBUtil::getInstance()->getDB();
			$key = random_bytes(32);
			$stmt = $db->prepare("
				insert into sys_device_authorization (user_id, device_auth_key)
				values (?, ?)
				on duplicate key update device_auth_key = values(device_auth_key)
			");
			$stmt->bind_param("is", $userId, $key);
			$stmt->execute();

			$stmt = $db->prepare("delete from sys_device_counter where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
		}

		public function hasDeviceAuth($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select 1 from sys_device_authorization where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}

		// GET /api/adapter/device-auth?ppp_id=...&device=...&action=authorize|deauthorize&counter=...&sig=...
		//
		//   device  16 lowercase hex characters (8 bytes) the device chose for
		//           itself on first use and keeps with its persisted state.
		//           Optional: a request without it is the pre-per-device form
		//           and addresses the account's "legacy" device (device_id '').
		//   sig     HMAC-SHA256 over ppp_id|device|action|counter with the
		//           account's device_auth_key -- or ppp_id|action|counter in
		//           the legacy form -- as 64 lowercase hex characters.
		//
		// Returns an HTTP status: 200 (accepted, idempotent on a repeated
		// counter), 400 (malformed request), 403 (unknown ppp_id / bad
		// signature / stale counter / too many devices on the account).
		public function handleRequest($pppId, $action, $counterRaw, $sig, $deviceId = "") {
			if (!preg_match('/^g[0-9]{9}$/', $pppId)) return 400;
			if ($action !== "authorize" && $action !== "deauthorize") return 400;
			if (!preg_match('/^(0|[1-9][0-9]*)$/', $counterRaw)) return 400;
			if (!preg_match('/^[0-9a-f]{64}$/', $sig)) return 400;
			if ($deviceId !== "" && !preg_match('/^[0-9a-f]{16}$/', $deviceId)) return 400;

			$counter = (int) $counterRaw;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select a.user_id, a.device_auth_key
				from sys_device_authorization a
				inner join sys_users u on u.id = a.user_id
				where u.dion_ppp_id = ?
			");
			$stmt->bind_param("s", $pppId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);
			if (count($result) === 0) return 403;

			$account = $result[0];
			$userId = (int) $account["user_id"];
			$message = $deviceId === ""
				? $pppId."|".$action."|".$counterRaw
				: $pppId."|".$deviceId."|".$action."|".$counterRaw;
			$expectedSig = hash_hmac("sha256", $message, $account["device_auth_key"]);
			if (!hash_equals($expectedSig, $sig)) return 403;

			// The signature proves the key, so a device id seen for the first
			// time is simply this account's next device: its row is created
			// at the counter it presents. Only a valid signature gets this
			// far, so an attacker without the key cannot fill the account up.
			$stmt = $db->prepare("select id, counter from sys_device_counter where user_id = ? and device_id = ?");
			$stmt->bind_param("is", $userId, $deviceId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);

			if (count($result) === 0) {
				$stmt = $db->prepare("select count(*) as n from sys_device_counter where user_id = ?");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				if ((int) DBUtil::fancy_get_result($stmt)[0]["n"] >= self::MAX_DEVICES_PER_ACCOUNT) return 403;

				if ($action === "authorize") {
					$stmt = $db->prepare("insert into sys_device_counter (user_id, device_id, counter, authorized, authorized_until) values (?, ?, ?, 1, date_add(now(), interval 30 minute))");
				} else {
					$stmt = $db->prepare("insert into sys_device_counter (user_id, device_id, counter, authorized, authorized_until) values (?, ?, ?, 0, null)");
				}
				$stmt->bind_param("isi", $userId, $deviceId, $counter);
				$stmt->execute();
				return 200;
			}

			$row = $result[0];
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
				$stmt = $db->prepare("update sys_device_counter set counter = ?, authorized = 1, authorized_until = date_add(now(), interval 30 minute) where id = ?");
			} else {
				$stmt = $db->prepare("update sys_device_counter set counter = ?, authorized = 0, authorized_until = null where id = ?");
			}
			$stmt->bind_param("ii", $counter, $row["id"]);
			$stmt->execute();
			return 200;
		}

		// Whether any of the account's devices holds an open authorization
		// window right now (the relay policy asks the same question of the
		// sender's account, see mail/relayPolicy.js).
		public function isAuthorized($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select 1 from sys_device_counter where user_id = ? and authorized = 1 and authorized_until > now() limit 1");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
		}
	}
?>
