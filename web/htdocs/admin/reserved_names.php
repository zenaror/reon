<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/ReservedNamesUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$notice = null;
	$noticeKind = "ok";

	// The list of usernames nobody may register (Users -> Reserved names).
	// Every change is recorded in the admin log, like everything else here.
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = (string)($_POST["action"] ?? "");
		$error = "";

		if ($action === "add") {
			$error = ReservedNamesUtil::add($_POST["name"] ?? "", $_POST["comment"] ?? "", AdminUtil::currentAdminId());
			if ($error === "") {
				$admin->log("reserved.add", (string)ReservedNamesUtil::normalize($_POST["name"] ?? ""), trim((string)($_POST["comment"] ?? "")));
				$notice = TemplateUtil::translate("admin.reserved-added");
			}
		} elseif ($action === "bulk") {
			[$entries, $problems] = ReservedNamesUtil::parseBulk($_POST["bulk"] ?? "");
			$added = 0;
			$skipped = 0;
			foreach ($entries as [$name, $comment]) {
				if (ReservedNamesUtil::add($name, $comment, AdminUtil::currentAdminId()) === "") {
					$added++;
					$admin->log("reserved.add", $name, $comment);
				} else {
					$skipped++;
				}
			}
			$notice = TemplateUtil::translate("admin.reserved-bulk-result", [
				"%added%" => $added, "%skipped%" => $skipped, "%bad%" => count($problems),
			]);
			if ($problems) $notice .= " (" . implode("; ", $problems) . ")";
			if ($added === 0 && ($skipped > 0 || $problems)) $noticeKind = "bad";
		} elseif ($action === "comment") {
			$error = ReservedNamesUtil::setComment($_POST["name"] ?? "", $_POST["comment"] ?? "");
			if ($error === "") {
				$admin->log("reserved.comment", (string)ReservedNamesUtil::normalize($_POST["name"] ?? ""), trim((string)($_POST["comment"] ?? "")));
				$notice = TemplateUtil::translate("admin.reserved-saved");
			}
		} elseif ($action === "remove") {
			$error = ReservedNamesUtil::remove($_POST["name"] ?? "");
			if ($error === "") {
				$admin->log("reserved.remove", (string)ReservedNamesUtil::normalize($_POST["name"] ?? ""));
				$notice = TemplateUtil::translate("admin.reserved-removed");
			}
		}

		if ($error !== "") {
			$notice = TemplateUtil::translate("admin.err-" . $error);
			$noticeKind = "bad";
		}
	}

	$rows = ReservedNamesUtil::all();

	echo TemplateUtil::render("admin/reserved_names", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"rows" => $rows,
		"total" => count($rows),
	]);
