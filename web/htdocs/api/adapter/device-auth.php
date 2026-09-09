<?php
	require_once("../../../classes/DeviceAuthUtil.php");

	// GET /api/adapter/device-auth?ppp_id=...&device=...&action=authorize|deauthorize&counter=...&sig=...
	// Called directly by libmobile-derived frontends (not a browser), over
	// the device.auth.dion.ne.jp hostname. Response body is intentionally
	// empty -- only the HTTP status code is part of the contract. `device`
	// is optional (see DeviceAuthUtil::handleRequest for both forms).
	http_response_code(DeviceAuthUtil::getInstance()->handleRequest(
		$_GET["ppp_id"] ?? "",
		$_GET["action"] ?? "",
		$_GET["counter"] ?? "",
		$_GET["sig"] ?? "",
		$_GET["device"] ?? ""
	));
?>
