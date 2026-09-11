<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/TrainerPageUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$trainer = TrainerPageUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$url = (string)($_POST["page"] ?? "");

		[$ok, $detail] = $trainer->write($url, $_POST["html"] ?? "");
		if ($ok) {
			$admin->log("trainer.save", $url, strlen((string)($_POST["html"] ?? "")) . " bytes");
			$notice = TemplateUtil::translate("admin.trainer-saved");
		} else {
			$notice = TemplateUtil::translate("admin.trainer-failed", ["%detail%" => $detail]);
			$noticeKind = "bad";
		}

		if ($ok) {
			header("Location: /admin/trainer.php?page=" . urlencode($url) . "&saved=1");
			return;
		}
	}

	if (isset($_GET["page"])) {
		$page = $trainer->page($_GET["page"]);
		if ($page === null) {
			http_response_code(404);
			return;
		}
		if (isset($_GET["saved"])) $notice = TemplateUtil::translate("admin.trainer-saved");

		echo TemplateUtil::render("admin/trainer_edit", [
			"notice" => $notice,
			"notice_kind" => $noticeKind,
			"page" => $page,
			"html" => $trainer->read($_GET["page"]),
		]);
		return;
	}

	echo TemplateUtil::render("admin/trainer", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"pages" => $trainer->pages(),
	]);
