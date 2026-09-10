<?php
	// What the bell's dropdown shows when it is opened.
	//
	// POST rather than GET, and CSRF-checked like every other POST here,
	// because opening the bell is also what marks its notifications read --
	// a state change, and one a prefetch or a stray <img> must not be able
	// to trigger on someone's behalf.
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/NotificationUtil.php");
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
		echo json_encode(["error" => "post-only"]);
		return;
	}

	// The token is compared here rather than through CsrfUtil::check(),
	// which answers a rejection with a rendered HTML page -- the right thing
	// for a form submission, the wrong thing for a caller that parses JSON.
	$sent = (string)($_POST[CsrfUtil::FIELD] ?? "");
	$held = (string)($_SESSION["csrf_token"] ?? "");
	if ($held === "" || $sent === "" || !hash_equals($held, $sent)) {
		http_response_code(403);
		echo json_encode(["error" => "bad-token"]);
		return;
	}

	$userId = $_SESSION["user_id"];
	$notify = NotificationUtil::getInstance();

	// Listed before marking, so the rows come back carrying the unread flags
	// they had when the reader opened the menu rather than the flags this
	// request is about to clear.
	$items = $notify->listForUser($userId, NotificationUtil::RECENT);
	$unread = $notify->countUnread($userId);
	$notify->markAllRead($userId);

	echo json_encode([
		"items" => $items,
		"unread" => $unread,
		"total" => $notify->countForUser($userId),
	]);
