<?php
	require_once("../../../classes/DeviceAuthUtil.php");

	// GET /api/adapter/device-auth?ppp_id=...&device=...&action=authorize|deauthorize&counter=...&sig=...
	// GET /api/adapter/device-auth?ppp_id=...&device=...&action=query&sig=...
	// Called directly by libmobile-derived frontends (not a browser), over
	// the device.auth.dion.ne.jp hostname. For authorize/deauthorize the
	// response body is intentionally empty -- only the HTTP status code is
	// part of the contract; query answers 200 with "<counter> <sig>" as the
	// whole body (no trailing newline, Content-Length set), see
	// DeviceAuthUtil::handleQuery. `device` is optional (both forms there).
	$util = DeviceAuthUtil::getInstance();
	if (($_GET["action"] ?? "") === "query") {
		[$status, $body] = $util->handleQuery($_GET["ppp_id"] ?? "", $_GET["sig"] ?? "", $_GET["device"] ?? "");
		http_response_code($status);
		if ($status === 200) {
			header("Content-Type: text/plain");
			header("Content-Length: ".strlen($body));
			echo $body;
		}
	} else {
		http_response_code($util->handleRequest(
			$_GET["ppp_id"] ?? "",
			$_GET["action"] ?? "",
			$_GET["counter"] ?? "",
			$_GET["sig"] ?? "",
			$_GET["device"] ?? "",
			$_SERVER["REMOTE_ADDR"] ?? null
		));
	}
?>
