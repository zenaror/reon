<?php
	include("../classes/DBUtil.php");
	include("../classes/RelayUtil.php");

	// One-off: provisions a relay token+number for every account that
	// predates that feature (created before signup started doing this
	// automatically), so their account page has something to show and
	// they can configure it manually. Safe to re-run -- only touches
	// accounts RelayUtil::getForUser() still reports as having none.

	function main() {
		$db = DBUtil::getInstance()->getDB();
		$relay = RelayUtil::getInstance();

		$result = $db->query("select id from sys_users order by id asc");
		$total = 0;
		$provisioned = 0;

		while ($row = $result->fetch_assoc()) {
			$total++;
			if ($relay->getForUser($row["id"]) !== null) continue;

			$relay->provisionForUser($row["id"]);
			if ($relay->getForUser($row["id"]) !== null) {
				$provisioned++;
			} else {
				echo "id ".$row["id"].": failed (mobile-relay unreachable?)\n";
			}
		}

		echo "Checked $total accounts, provisioned $provisioned.\n";
	}

	main();
