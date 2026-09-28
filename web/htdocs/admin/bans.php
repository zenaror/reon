<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/IpBanUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$bans = IpBanUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	// Banned addresses (fail2ban), with the reason for each. A ban added here
	// always carries a reason; a ban lifted here is recorded like the rest of
	// what an administrator does.
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = (string)($_POST["action"] ?? "");
		$callerIp = (string)($_SERVER["REMOTE_ADDR"] ?? "");
		$serverIp = (string)($_SERVER["SERVER_ADDR"] ?? "");

		if ($action === "ban") {
			$ip = trim((string)($_POST["ip"] ?? ""));
			$reason = trim((string)($_POST["reason"] ?? ""));
			[$ok, $key, $detail] = $bans->ban($ip, $reason, AdminUtil::currentAdminId(), $callerIp, $serverIp);
			$admin->log($ok ? "ip.ban" : "ip.ban-failed", $ip, $ok ? $reason : ($key . ($detail !== "" ? ": " . $detail : "")));
			if ($ok) {
				$notice = TemplateUtil::translate("admin.bans-added", ["%ip%" => $ip]);
			} else {
				$notice = TemplateUtil::translate("admin.err-" . $key) . ($detail !== "" ? " (" . $detail . ")" : "");
				$noticeKind = "bad";
			}
		} elseif ($action === "unban") {
			$ip = trim((string)($_POST["ip"] ?? ""));
			[$ok, $key, $detail] = $bans->unban($ip, AdminUtil::currentAdminId(), (string)($_POST["note"] ?? ""));
			$admin->log($ok ? "ip.unban" : "ip.unban-failed", $ip, $ok ? "" : ($key . ($detail !== "" ? ": " . $detail : "")));
			if ($ok) {
				$notice = TemplateUtil::translate("admin.bans-lifted", ["%ip%" => $ip]);
			} else {
				$notice = TemplateUtil::translate("admin.err-" . $key) . ($detail !== "" ? " (" . $detail . ")" : "");
				$noticeKind = "bad";
			}
		} else {
			http_response_code(400);
			return;
		}
	}

	[$rows, $problem] = $bans->current();

	echo TemplateUtil::render("admin/bans", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"rows" => $rows,
		"available" => $bans->available(),
		"problem" => ($problem !== "" && $problem !== "unavailable") ? $problem : "",
		"your_ip" => (string)($_SERVER["REMOTE_ADDR"] ?? ""),
	]);
