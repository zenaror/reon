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
		$action = (string)($_POST["action"] ?? "save");
		$html = (string)($_POST["html"] ?? "");

		if ($action === "create") {
			[$ok, $detail] = $trainer->create(
				(string)($_POST["dir"] ?? ""), (string)($_POST["name"] ?? ""), $html);
			if ($ok) {
				$admin->log("trainer.create", $detail);
				header("Location: /admin/trainer.php?page=" . urlencode($detail) . "&saved=1");
				return;
			}
			$notice = TemplateUtil::translate("admin.trainer-failed", ["%detail%" => $detail]);
			$noticeKind = "bad";

		} elseif ($action === "delete") {
			$url = (string)($_POST["page"] ?? "");
			[$ok, $detail] = $trainer->delete($url);
			$admin->log($ok ? "trainer.delete" : "trainer.delete-failed", $url, $detail);
			if ($ok) {
				header("Location: /admin/trainer.php?deleted=1");
				return;
			}
			$notice = TemplateUtil::translate("admin.trainer-failed", ["%detail%" => $detail]);
			$noticeKind = "bad";

		} elseif ($action === "upload") {
			$url = (string)($_POST["page"] ?? "");
			$file = $_FILES["image"] ?? null;

			if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			    || !is_uploaded_file($file["tmp_name"])) {
				$notice = TemplateUtil::translate("admin.trainer-img-none");
				$noticeKind = "bad";
			} else {
				// The name is taken from the field when given, and otherwise
				// from what the browser called the file; either way it goes
				// through cleanImageName, which is what decides.
				$name = trim((string)($_POST["name"] ?? ""));
				if ($name === "") $name = (string)$file["name"];

				$bytes = (string)@file_get_contents($file["tmp_name"]);
				[$ok, $detail, $facts] = $trainer->addImage($url, $bytes, $name);

				if ($ok) {
					$admin->log("trainer.image", $url, $detail . " " . json_encode($facts));
					header("Location: /admin/trainer.php?page=" . urlencode($url) . "&uploaded=" . urlencode($detail));
					return;
				}
				// The rule and the file's own numbers together: "1BPP only"
				// is the rule, "yours is 24BPP, 200x150" is what sends
				// someone to fix it.
				// The size limits ride along on every one of these: only one
				// message uses them, and leaving them out showed "%w%x%h%"
				// on screen for exactly the message that most needed to be
				// clear.
				$notice = TemplateUtil::translate("admin.trainer-img-" . $detail, [
					"%w%" => TrainerPageUtil::IMAGE_MAX_W,
					"%h%" => TrainerPageUtil::IMAGE_MAX_H,
				]) . ($facts ? " (" . $trainer->describeFacts($facts) . ")" : "");
				$noticeKind = "bad";
			}

		} elseif ($action === "image-delete") {
			$url = (string)($_POST["page"] ?? "");
			[$ok, $detail] = $trainer->deleteImage($url, (string)($_POST["name"] ?? ""));
			$admin->log($ok ? "trainer.image-delete" : "trainer.image-delete-failed", $url, (string)($_POST["name"] ?? ""));
			if ($ok) {
				header("Location: /admin/trainer.php?page=" . urlencode($url) . "&deleted-image=1");
				return;
			}
			$notice = TemplateUtil::translate("admin.trainer-failed", ["%detail%" => $detail]);
			$noticeKind = "bad";

		} else {
			$url = (string)($_POST["page"] ?? "");
			[$ok, $detail] = $trainer->write($url, $html);
			if ($ok) {
				$admin->log("trainer.save", $url, strlen($html) . " bytes");
				header("Location: /admin/trainer.php?page=" . urlencode($url) . "&saved=1");
				return;
			}
			$admin->log("trainer.save-failed", $url, $detail);
			$notice = TemplateUtil::translate("admin.trainer-failed", ["%detail%" => $detail]);
			$noticeKind = "bad";
		}
	}

	// The new-page form is its own view, like composing rather than replying.
	if (isset($_GET["new"])) {
		echo TemplateUtil::render("admin/trainer_new", [
			"notice" => $notice,
			"notice_kind" => $noticeKind,
			"can_create" => $trainer->canCreate(),
			"directories" => $trainer->directories(),
			"root" => $trainer->root(),
		]);
		return;
	}

	if (isset($_GET["page"])) {
		$page = $trainer->page($_GET["page"]);
		if ($page === null) {
			http_response_code(404);
			return;
		}
		if (isset($_GET["saved"])) $notice = TemplateUtil::translate("admin.trainer-saved");
		if (isset($_GET["uploaded"])) {
			$notice = TemplateUtil::translate("admin.trainer-img-added", ["%name%" => $_GET["uploaded"]]);
		}
		if (isset($_GET["deleted-image"])) $notice = TemplateUtil::translate("admin.trainer-img-deleted");

		echo TemplateUtil::render("admin/trainer_edit", [
			"notice" => $notice,
			"notice_kind" => $noticeKind,
			"page" => $page,
			"html" => $trainer->read($_GET["page"]),
			"tags" => TrainerPageUtil::KNOWN_TAGS,
			"images" => $trainer->images($_GET["page"]),
			"image_max_w" => TrainerPageUtil::IMAGE_MAX_W,
			"image_max_h" => TrainerPageUtil::IMAGE_MAX_H,
		]);
		return;
	}

	if (isset($_GET["deleted"])) $notice = TemplateUtil::translate("admin.trainer-deleted");

	$pages = $trainer->pages();
	// Whether anything here can be changed at all. The web server runs as its
	// own user and these files belong to the account that deployed them, so
	// "read-only" is the normal state until somebody grants it -- and saying
	// which user needs what is the difference between a fixable problem and a
	// mysterious one.
	$stuck = [];
	foreach ($pages as $page) {
		if (!$page["writable"]) $stuck[] = $page["url"];
	}

	echo TemplateUtil::render("admin/trainer", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"pages" => $pages,
		"can_create" => $trainer->canCreate(),
		"stuck" => $stuck,
		"web_user" => function_exists("posix_getpwuid") && function_exists("posix_geteuid")
			? (posix_getpwuid(posix_geteuid())["name"] ?? "?")
			: "?",
		"root" => $trainer->root(),
	]);
