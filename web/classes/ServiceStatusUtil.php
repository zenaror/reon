<?php
	require_once("DBUtil.php");

	// Reads sys_service_status, refreshed periodically by
	// web/scripts/check_service_status.php (see examples/systemd/
	// reon_service_status.*). Read-only from the web app's side -- this
	// class never writes.
	class ServiceStatusUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new ServiceStatusUtil();
			}
			return self::$instance;
		}

		// Returns ["web" => ["status" => "up"|"down"|"unknown", "detail" => string|null], ...]
		// for every service the checker knows about, keyed by service name.
		// A service that has never been checked (row absent) comes back as
		// "unknown" rather than being omitted, so callers don't need to
		// isset()-guard every lookup.
		public function getAll() {
			$known = ["web", "mail", "mobile_relay", "relay_policy"];
			$out = [];
			foreach ($known as $service) {
				$out[$service] = ["status" => "unknown", "detail" => null];
			}

			$db = DBUtil::getInstance()->getDB();
			$result = $db->query("select service, status, detail from sys_service_status");
			if ($result) {
				while ($row = $result->fetch_assoc()) {
					$out[$row["service"]] = ["status" => $row["status"], "detail" => $row["detail"]];
				}
			}

			return $out;
		}
	}
?>
