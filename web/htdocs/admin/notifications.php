<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/NotificationUtil.php");
	require_once("../../classes/DBUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$notify = NotificationUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";
	$blank = ["to" => "all", "users" => [], "category" => "admin", "title" => "", "body" => "", "link" => ""];
	$form = $blank;

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();

		$form["to"] = ($_POST["to"] ?? "all") === "user" ? "user" : "all";
		// Several accounts at once, so one announcement to a handful of
		// people is one act rather than the same text typed five times.
		// Whatever arrives is reduced to ids that actually exist -- the
		// picker posts what it was given, and what it was given came from
		// the page, which is not a reason to trust it.
		$form["users"] = [];
		foreach ((array)($_POST["users"] ?? []) as $candidate) {
			$id = (int)$candidate;
			if ($id > 0 && !in_array($id, $form["users"], true)) $form["users"][] = $id;
		}
		$form["category"] = in_array($_POST["category"] ?? "", NotificationUtil::CATEGORIES, true) ? $_POST["category"] : "admin";
		$form["title"] = trim((string)($_POST["title"] ?? ""));
		$form["body"] = trim((string)($_POST["body"] ?? ""));
		$form["link"] = trim((string)($_POST["link"] ?? ""));

		// A link is only ever a path on this site. An admin typing an
		// off-site URL here would be putting a link into every player's
		// notification history that nobody can check afterwards, and the
		// panel is not a place to build one of those by accident.
		if ($form["link"] !== "" && substr($form["link"], 0, 1) !== "/") {
			$form["link"] = "/" . ltrim($form["link"], "/");
		}

		$opts = [
			"title" => $form["title"],
			"body" => $form["body"] !== "" ? $form["body"] : null,
			"link" => $form["link"] !== "" ? $form["link"] : null,
			"by" => AdminUtil::currentAdminId(),
		];

		if ($form["title"] === "") {
			$notice = TemplateUtil::translate("admin.notify-need-title");
			$noticeKind = "bad";
		} elseif ($form["to"] === "all") {
			[$sent, $batch] = $notify->addForAll($form["category"], $opts);
			$admin->log("notify.broadcast", $batch, $form["title"]);
			$notice = TemplateUtil::translate("admin.notify-sent-all", ["%count%" => $sent]);
			$form = $blank;
		} else {
			// Every id is resolved to a real account before anything is
			// written, so one bad id is caught here rather than leaving half
			// the list notified and half not.
			$targets = [];
			foreach ($form["users"] as $id) {
				$target = $admin->getUser($id);
				if ($target !== null) $targets[] = $target;
			}

			if ($targets === []) {
				$notice = TemplateUtil::translate("admin.notify-no-user");
				$noticeKind = "bad";
			} else {
				// One batch ties the whole send together, exactly as a
				// send-to-everybody does, so the list below shows it as one
				// announcement instead of one row per person.
				$opts["batch"] = bin2hex(random_bytes(8));
				$names = [];
				foreach ($targets as $target) {
					$notify->add($target["id"], $form["category"], $opts);
					$names[] = $target["username"];
				}
				$admin->log("notify.send", implode(", ", $names), $form["title"]);
				$notice = count($names) === 1
					? TemplateUtil::translate("admin.notify-sent", ["%who%" => $names[0]])
					: TemplateUtil::translate("admin.notify-sent-many", ["%count%" => count($names)]);
				$form = $blank;
				$form["to"] = "user";
			}
		}
	}

	// Who can be written to, and what was written lately. Only notifications
	// a person wrote are listed here: the automatic ones are a river, and
	// this page is about what was said by hand.
	$db = DBUtil::getInstance()->getDB();
	$users = [];
	$result = $db->query("select id, username from sys_users order by username");
	if ($result) {
		while ($row = $result->fetch_assoc()) $users[] = $row;
	}

	// Grouped by batch, because a broadcast is one row per account: listing
	// them raw would show the same announcement eleven times and name a
	// different reader each time, as if each had got their own.
	$recent = [];
	$result = $db->query(
		"select min(n.id) as id, n.category, n.title, n.body, n.batch,
		        max(n.created_at) as created_at, count(*) as recipients,
		        max(u.username) as username
		   from sys_notifications n
		   left join sys_users u on u.id = n.user_id
		  where n.created_by is not null
		  group by coalesce(n.batch, cast(n.id as char)), n.batch, n.category, n.title, n.body
		  order by id desc
		  limit 20"
	);
	if ($result) {
		while ($row = $result->fetch_assoc()) {
			$row["created_at"] = strtotime($row["created_at"]);
			$recent[] = $row;
		}
	}

	echo TemplateUtil::render("admin/notifications", [
		"notice" => $notice,
		"notice_kind" => $noticeKind,
		"form" => $form,
		"users" => $users,
		"categories" => NotificationUtil::CATEGORIES,
		"recent" => $recent,
	]);
