<?php
	include("../classes/DBUtil.php");
	include("../classes/RelayUtil.php");

	// Run periodically (systemd timer, see examples/systemd/reon-service-status.*)
	// to refresh sys_service_status, which the homepage reads to show live
	// up/down status instead of a hardcoded "-". Every check is a plain TCP
	// connect probe on the service's own port -- no shell_exec/systemctl,
	// so this only ever needs normal DB + network access to run.

	function checkTcp($host, $port, $timeout = 2) {
		$conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
		if ($conn === false) return false;
		fclose($conn);
		return true;
	}

	function upsertStatus($db, $service, $status, $detail) {
		$stmt = $db->prepare("
			INSERT INTO sys_service_status (service, status, detail) VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE status = VALUES(status), detail = VALUES(detail)
		");
		$stmt->bind_param("sss", $service, $status, $detail);
		$stmt->execute();
	}

	function main() {
		$db = DBUtil::getInstance()->getDB();

		$webUp = checkTcp("127.0.0.1", 80);
		upsertStatus($db, "web", $webUp ? "up" : "down", null);

		$mailUp = checkTcp("127.0.0.1", 25);
		upsertStatus($db, "mail", $mailUp ? "up" : "down", null);

		$relayUp = checkTcp("127.0.0.1", 31227);
		$activeCount = null;
		if ($relayUp) {
			// Exact live count, kept in sync by mobile-relay itself
			// (relay_stats.connected_count, written on every connect()/
			// disconnect() in peers.py) -- not an approximation.
			$relayDb = RelayUtil::getInstance()->getRelayDB();
			if ($relayDb) {
				$result = $relayDb->query("
					SELECT value FROM relay_stats WHERE name = 'connected_count'
				");
				if ($result && $result->num_rows > 0) {
					$activeCount = (string)$result->fetch_assoc()["value"];
				} else {
					$activeCount = "0";
				}
			}
		}
		upsertStatus($db, "mobile_relay", $relayUp ? "up" : "down", $activeCount);

		$relayPolicyUp = checkTcp("127.0.0.1", 10045);
		upsertStatus($db, "relay_policy", $relayPolicyUp ? "up" : "down", null);
	}

	main();
