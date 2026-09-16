<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/DeviceAuthUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$devices = DeviceAuthUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = $_POST["action"] ?? "";
		$userId = (int)($_POST["user"] ?? 0);

		// Every branch returns either null (done) or a reason string, which
		// is turned into the same message the reader sees. A silent refusal
		// is the worst answer a panel can give.
		$error = null;
		if ($action === "ban") {
			$error = $admin->ban($userId, $_POST["reason"] ?? "");
		} elseif ($action === "unban") {
			$error = $admin->unban($userId);
		} elseif ($action === "admin-grant") {
			$error = $admin->setAdmin($userId, true);
		} elseif ($action === "admin-revoke") {
			$error = $admin->setAdmin($userId, false);
		} elseif ($action === "device-block" || $action === "device-unblock") {
			$target = $admin->getUser($userId);
			if ($target === null) {
				$error = "no-such-user";
			} else {
				$blocked = $action === "device-block";
				// Scoped by user inside DeviceAuthUtil, so a device id from
				// another account simply matches nothing.
				$devices->setBlocked($userId, (string)($_POST["device"] ?? ""), $blocked);
				$admin->log($blocked ? "device.block" : "device.unblock",
					$target["username"], (string)($_POST["device"] ?? ""));
			}
		}

		if ($error !== null) {
			$notice = TemplateUtil::translate("admin.err-" . $error);
			$noticeKind = "bad";
		}

		// Redirect after a successful POST so a refresh does not replay it;
		// a refusal keeps the page so its message is still on screen.
		if ($error === null) {
			header("Location: /admin/users.php" . ($userId > 0 ? "?id=" . $userId : ""));
			return;
		}
	}

	// One account, with its consoles.
	if (isset($_GET["id"])) {
		$user = $admin->getUser($_GET["id"]);
		if ($user === null) {
			http_response_code(404);
			return;
		}
		echo TemplateUtil::render("admin/user", [
			"notice" => $notice,
			"notice_kind" => $noticeKind,
			"user" => $user,
			"devices" => $devices->listDevices($user["id"]),
		]);
		return;
	}

	$q = trim((string)($_GET["q"] ?? ""));
	$page = max(1, (int)($_GET["page"] ?? 1));
	[$rows, $total, $pages] = $admin->listUsers($q, $page);

	echo TemplateUtil::render("admin/users", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"users" => $rows,
		"q" => $q,
		"page" => $page,
		"pages" => $pages,
		"total" => $total,
	]);
