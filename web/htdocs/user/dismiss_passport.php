<?php
	// Marks the "your passport to REON" modal as seen for the signed-in
	// account. Called once, whichever way the modal closes -- the X, the
	// backdrop, Escape, or the download button itself -- via Bootstrap's
	// 'hidden.bs.modal' event, so a visitor who closes without downloading
	// still isn't shown it again on the next page.
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/CsrfUtil.php");
	session_start();

	header("Content-Type: application/json");
	header("Cache-Control: no-store");

	if (!SessionUtil::getInstance()->isSessionActive()) {
		http_response_code(401);
		echo json_encode(["error" => "not-signed-in"]);
		return;
	}

	if ($_SERVER["REQUEST_METHOD"] !== "POST") {
		http_response_code(405);
		echo json_encode(["error" => "method-not-allowed"]);
		return;
	}
	CsrfUtil::check();

	require_once("../../classes/DBUtil.php");
	$db = DBUtil::getInstance()->getDB();
	$stmt = $db->prepare("update sys_users set passport_seen_at = now() where id = ? and passport_seen_at is null");
	$userId = (int)$_SESSION["user_id"];
	$stmt->bind_param("i", $userId);
	$stmt->execute();

	echo json_encode(["ok" => true]);
