<?php
	// Counts for the webmail's background check. Deliberately read-only: it
	// must never call markInboxSeen(), or polling would clear the "new"
	// marker every minute without anyone having looked at anything.
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/MailUtil.php");
	require_once("../../classes/NotificationUtil.php");
	session_start();

	header("Content-Type: application/json");
	header("Cache-Control: no-store");

	if (!SessionUtil::getInstance()->isSessionActive()) {
		http_response_code(401);
		echo json_encode(["error" => "not-signed-in"]);
		return;
	}

	$userId = $_SESSION["user_id"];
	$mail = MailUtil::getInstance();

	// The bell rides along on this poll rather than opening a second one:
	// one request a minute already exists, and the two counts change for the
	// same reasons.
	echo json_encode([
		"count" => $mail->countForUser($userId),
		"new" => $mail->countNewForUser($userId),
		"notify_new" => NotificationUtil::getInstance()->countUnread($userId),
	]);
