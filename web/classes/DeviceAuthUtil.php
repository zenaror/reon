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
		// again. The device rows stay: a device's id comes from its hardware,
		// so it will present the same id under the new key, and keeping the
		// row keeps its nickname, its block and its history. Open windows are
		// closed here since nothing signed under the old key counts any more.
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

			$stmt = $db->prepare("update sys_device_counter set authorized = 0, authorized_until = null where user_id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
		}

		// The account page's "connected devices" list, most recently seen
		// first. Each row carries the pairing code the device itself can show
		// (see pairingCode) so the owner can tell which physical device a row
		// is before naming or blocking it.
		public function listDevices($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("
				select device_id, nickname, counter, authorized, authorized_until, blocked, last_ip, last_seen_at, created_at,
				       (authorized = 1 and authorized_until > now()) as authorized_now
				from sys_device_counter
				where user_id = ?
				order by coalesce(last_seen_at, created_at) desc, id desc
			");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$rows = DBUtil::fancy_get_result($stmt);
			foreach ($rows as &$row) {
				$row["pairing_code"] = self::pairingCode($row["device_id"]);
			}
			return $rows;
		}

		// What the device shows on its own screen/console so the owner can
		// match it to a row here: the first 8 hex digits of the device id,
		// upper case, split 4-4. The legacy device (requests without an id)
		// has nothing to show.
		public static function pairingCode($deviceId) {
			if ($deviceId === "" || $deviceId === null) return "----";
			return strtoupper(substr($deviceId, 0, 4))."-".strtoupper(substr($deviceId, 4, 4));
		}

		// Nickname given by the owner; empty clears it. Returns false when
		// the device is not this account's.
		public function setNickname($userId, $deviceId, $nickname) {
			$nickname = trim($nickname);
			if ($nickname === "") $nickname = null;
			elseif (mb_strlen($nickname) > 32) $nickname = mb_substr($nickname, 0, 32);
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_device_counter set nickname = ? where user_id = ? and device_id = ?");
			$stmt->bind_param("sis", $nickname, $userId, $deviceId);
			$stmt->execute();
			return $stmt->affected_rows >= 0 && $this->ownsDevice($userId, $deviceId);
		}

		// Blocking keeps the row and its counter and answers 403 to everything
		// the device sends; unblocking lets it continue where it was. A block
		// also closes any window the device had open.
		public function setBlocked($userId, $deviceId, $blocked) {
			if (!$this->ownsDevice($userId, $deviceId)) return false;
			$db = DBUtil::getInstance()->getDB();
			$flag = $blocked ? 1 : 0;
			if ($blocked) {
				$stmt = $db->prepare("update sys_device_counter set blocked = 1, authorized = 0, authorized_until = null where user_id = ? and device_id = ?");
				$stmt->bind_param("is", $userId, $deviceId);
			} else {
				$stmt = $db->prepare("update sys_device_counter set blocked = 0 where user_id = ? and device_id = ?");
				$stmt->bind_param("is", $userId, $deviceId);
			}
			$stmt->execute();
			return true;
		}

		private function ownsDevice($userId, $deviceId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select 1 from sys_device_counter where user_id = ? and device_id = ?");
			$stmt->bind_param("is", $userId, $deviceId);
			$stmt->execute();
			return count(DBUtil::fancy_get_result($stmt)) > 0;
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
		// signature / stale counter / blocked device / too many devices on
		// the account). $ip is recorded as where the device was last seen.
		public function handleRequest($pppId, $action, $counterRaw, $sig, $deviceId = "", $ip = null) {
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
			$stmt = $db->prepare("select id, counter, blocked from sys_device_counter where user_id = ? and device_id = ?");
			$stmt->bind_param("is", $userId, $deviceId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);

			if (count($result) === 0) {
				$stmt = $db->prepare("select count(*) as n from sys_device_counter where user_id = ?");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				if ((int) DBUtil::fancy_get_result($stmt)[0]["n"] >= self::MAX_DEVICES_PER_ACCOUNT) return 403;

				if ($action === "authorize") {
					$stmt = $db->prepare("insert into sys_device_counter (user_id, device_id, counter, authorized, authorized_until, last_ip, last_seen_at) values (?, ?, ?, 1, date_add(now(), interval 30 minute), ?, now())");
				} else {
					$stmt = $db->prepare("insert into sys_device_counter (user_id, device_id, counter, authorized, authorized_until, last_ip, last_seen_at) values (?, ?, ?, 0, null, ?, now())");
				}
				$stmt->bind_param("isis", $userId, $deviceId, $counter, $ip);
				$stmt->execute();
				return 200;
			}

			$row = $result[0];
			// Blocked on the account page: the row and its counter stay (so
			// nothing captured earlier can be replayed), the device gets the
			// same 403 as a stale counter until the owner unblocks it.
			if ((int) $row["blocked"] === 1) return 403;

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
				$stmt = $db->prepare("update sys_device_counter set counter = ?, authorized = 1, authorized_until = date_add(now(), interval 30 minute), last_ip = ?, last_seen_at = now() where id = ?");
			} else {
				$stmt = $db->prepare("update sys_device_counter set counter = ?, authorized = 0, authorized_until = null, last_ip = ?, last_seen_at = now() where id = ?");
			}
			$stmt->bind_param("isi", $counter, $ip, $row["id"]);
			$stmt->execute();
			return 200;
		}

		// GET /api/adapter/device-auth?ppp_id=...&device=...&action=query&sig=...
		//
		// Read-only: tells the device the last counter the server accepted
		// from it, so a device that lost its local state (or whose state
		// rolled back) can continue from there instead of being refused as
		// stale until its batches happen to overtake. sig is HMAC-SHA256
		// over ppp_id|device|query (ppp_id|query in the legacy form) -- no
		// counter, the request changes nothing, so replaying it gains an
		// attacker nothing but a number that is not secret. A device without
		// a row yet gets 0.
		//
		// The answer is signed too. This runs over plain HTTP on a network
		// the player controls (the game's own DNS is configurable), so an
		// intermediary could answer instead of us -- and a forged huge value
		// blindly adopted as "resume from here" would push the device's
		// counter to the top of its range and brick it on the next wrap. The
		// body is "<counter> <sig>" with sig = HMAC-SHA256 over
		// ppp_id|device|query-response|counter (ppp_id|query-response|counter
		// in the legacy form): only the key holder can produce it, and the
		// distinct label keeps it from ever passing as a request signature.
		// A replayed genuine answer can only be lower than the truth, which
		// a client that never moves its counter backwards ignores.
		//
		// With `counter=<local>` on the query the device spends the next
		// value of its own counter on the query -- strictly increasing per
		// query, even while blocked -- and the answer echoes it back inside
		// the signed message: "<counter> <local> <sig>" over ppp_id|device|
		// query-response|<counter>|<local>. That makes it a nonce without the
		// device needing a random source: a recorded answer cannot be
		// replayed against a later query. The echoed value is never stored
		// here and never compared with the device's row (it runs ahead of the
		// last counter accepted on authorize); the query stays read-only.
		// That is what makes a signed "blocked" safe to act on -- a blocked
		// device answered this way gets 200 "blocked <local> <sig>" over
		// ppp_id|device|query-response|blocked|<local>, and the libmobile core
		// refuses the session's network on it. A bare 403 would let anyone on
		// the path (the game's DNS is configurable) deny service to a
		// legitimate device with no key at all. Without the echo (older
		// cores) the answer is the old form and a blocked device still gets
		// 403, which those cores ignore as before.
		//
		// Returns [status, body]: 200 with the signed body above, 400
		// (malformed), 403 (unknown ppp_id / bad signature / blocked without
		// echo).
		public function handleQuery($pppId, $sig, $deviceId = "", $localRaw = "") {
			if (!preg_match('/^g[0-9]{9}$/', $pppId)) return [400, ""];
			if (!preg_match('/^[0-9a-f]{64}$/', $sig)) return [400, ""];
			if ($deviceId !== "" && !preg_match('/^[0-9a-f]{16}$/', $deviceId)) return [400, ""];
			if ($localRaw !== "" && !preg_match('/^(0|[1-9][0-9]*)$/', $localRaw)) return [400, ""];

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
			if (count($result) === 0) return [403, ""];

			$account = $result[0];
			$prefix = $deviceId === "" ? $pppId : $pppId."|".$deviceId;
			$message = $prefix."|query".($localRaw === "" ? "" : "|".$localRaw);
			$expectedSig = hash_hmac("sha256", $message, $account["device_auth_key"]);
			if (!hash_equals($expectedSig, $sig)) return [403, ""];

			$stmt = $db->prepare("select counter, blocked from sys_device_counter where user_id = ? and device_id = ?");
			$userId = (int) $account["user_id"];
			$stmt->bind_param("is", $userId, $deviceId);
			$stmt->execute();
			$result = DBUtil::fancy_get_result($stmt);
			if (count($result) > 0 && (int) $result[0]["blocked"] === 1) {
				if ($localRaw === "") return [403, ""];
				$responseMessage = $prefix."|query-response|blocked|".$localRaw;
				return [200, "blocked ".$localRaw." ".hash_hmac("sha256", $responseMessage, $account["device_auth_key"])];
			}

			// A device seen for the first time gets its row here, at 0, so it
			// shows up on the account's device list as soon as it has talked
			// to the server (the query runs at every session start; the first
			// authorize only comes when the game opens a mail connection, and
			// a device that just downloaded a page had none). Same cap as
			// handleRequest. "Last seen" stays with authorize/deauthorize:
			// those carry a fresh counter, while a replayed query must not be
			// able to stamp a recent time and a foreign IP on a device.
			if (count($result) === 0 && $deviceId !== "") {
				$stmt = $db->prepare("select count(*) as n from sys_device_counter where user_id = ?");
				$stmt->bind_param("i", $userId);
				$stmt->execute();
				if ((int) DBUtil::fancy_get_result($stmt)[0]["n"] < self::MAX_DEVICES_PER_ACCOUNT) {
					$stmt = $db->prepare("insert ignore into sys_device_counter (user_id, device_id, counter, authorized, authorized_until) values (?, ?, 0, 0, null)");
					$stmt->bind_param("is", $userId, $deviceId);
					$stmt->execute();
				}
			}
			$counter = count($result) === 0 ? "0" : (string) (int) $result[0]["counter"];
			if ($localRaw === "") {
				$responseMessage = $prefix."|query-response|".$counter;
				return [200, $counter." ".hash_hmac("sha256", $responseMessage, $account["device_auth_key"])];
			}
			$responseMessage = $prefix."|query-response|".$counter."|".$localRaw;
			return [200, $counter." ".$localRaw." ".hash_hmac("sha256", $responseMessage, $account["device_auth_key"])];
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
