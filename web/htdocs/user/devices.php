<?php
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/DBUtil.php");
	require_once("../../classes/DeviceAuthUtil.php");
	require_once("../../classes/CsrfUtil.php");
	session_start();

	// "Connected devices": every device that has authorized itself on this
	// account (one row per device id, see DeviceAuthUtil), with the pairing
	// code the device shows on its own side so the owner can tell which is
	// which, a nickname to keep once recognised, and a per-device block.
	// "Revoke all" (key rotation) lives here too.

	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /index.php");
		exit();
	}

	$userId = (int) $_SESSION["user_id"];
	$util = DeviceAuthUtil::getInstance();

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$op = $_POST["op"] ?? "";
		$device = $_POST["device"] ?? "";
		// The legacy device is the empty id; anything else is 16 lowercase hex.
		if ($device !== "" && !preg_match('/^[0-9a-f]{16}$/', $device)) $device = null;
		if ($device !== null) {
			if ($op === "nickname") {
				$util->setNickname($userId, $device, (string) ($_POST["nickname"] ?? ""));
			} elseif ($op === "block") {
				$util->setBlocked($userId, $device, true);
			} elseif ($op === "unblock") {
				$util->setBlocked($userId, $device, false);
			}
		}
		// Redirect after post so a refresh does not repeat the action.
		header("Location: /user/devices.php");
		exit();
	}

	// Dates are shown in the account's chosen time zone, like the rest of
	// the site; the database keeps UTC.
	$db = DBUtil::getInstance()->getDB();
	$stmt = $db->prepare("select timezone from sys_users where id = ?");
	$stmt->bind_param("i", $userId);
	$stmt->execute();
	$tzName = DBUtil::fancy_get_result($stmt)[0]["timezone"] ?? "Asia/Tokyo";
	try {
		$tz = new DateTimeZone($tzName);
	} catch (Exception $e) {
		$tz = new DateTimeZone("Asia/Tokyo");
	}
	$utc = new DateTimeZone("UTC");
	$now = new DateTime("now", $utc);

	$formatDate = function ($value) use ($tz, $utc) {
		if ($value === null || $value === "") return null;
		$d = new DateTime($value, $utc);
		$d->setTimezone($tz);
		return $d->format("Y-m-d H:i");
	};
	// "x minutes ago", coarse on purpose: this is for spotting the device
	// that just connected, not for auditing.
	$formatAgo = function ($value) use ($utc, $now) {
		if ($value === null || $value === "") return null;
		$seconds = $now->getTimestamp() - (new DateTime($value, $utc))->getTimestamp();
		if ($seconds < 60) return ["devices.ago-now", 0];
		if ($seconds < 3600) return ["devices.ago-minutes", intdiv($seconds, 60)];
		if ($seconds < 86400) return ["devices.ago-hours", intdiv($seconds, 3600)];
		return ["devices.ago-days", intdiv($seconds, 86400)];
	};

	$devices = [];
	foreach ($util->listDevices($userId) as $row) {
		$ago = $formatAgo($row["last_seen_at"]);
		$devices[] = [
			"device_id" => $row["device_id"],
			"legacy" => $row["device_id"] === "",
			"pairing_code" => $row["pairing_code"],
			"nickname" => $row["nickname"],
			"counter" => (int) $row["counter"],
			"authorized_now" => (int) $row["authorized_now"] === 1,
			"blocked" => (int) $row["blocked"] === 1,
			"last_ip" => $row["last_ip"],
			"first_seen" => $formatDate($row["created_at"]),
			"last_seen" => $formatDate($row["last_seen_at"]),
			"ago_key" => $ago === null ? null : $ago[0],
			"ago_n" => $ago === null ? 0 : $ago[1],
		];
	}

	echo TemplateUtil::render("/user/devices", [
		"devices" => $devices,
		"max_devices" => DeviceAuthUtil::MAX_DEVICES_PER_ACCOUNT,
	]);
?>
