<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/NewsMakerUtil.php");
	session_start();

	AdminUtil::guard();

	$admin = AdminUtil::getInstance();
	$maker = NewsMakerUtil::getInstance();

	$notice = null;
	$noticeKind = "ok";
	$results = null;
	$log = "";

	// Everything the form posts, in one shape, so saving and publishing read
	// the issue the same way.
	function readIssue($maker) {
		$issue = [
			"name" => trim((string)($_POST["name"] ?? "")),
			"template" => (string)($_POST["template"] ?? ""),
			"minigame" => (string)($_POST["minigame"] ?? ""),
			"message" => [],
			"date" => trim((string)($_POST["date"] ?? "")),
			"rankings" => [],
			"headline" => [],
			"body" => [],
		];
		foreach ([0, 1, 2] as $slot) {
			$issue["rankings"][] = (string)($_POST["ranking"][$slot] ?? "");
		}
		foreach (array_keys(NewsMakerUtil::LANGUAGES) as $language) {
			$issue["headline"][$language] = trim((string)($_POST["headline"][$language] ?? ""));
			$issue["message"][$language] = trim((string)($_POST["message"][$language] ?? ""));
			$issue["body"][$language] = (string)($_POST["body"][$language] ?? "");
		}
		$issue["slug"] = $maker->slug($issue["name"] !== "" ? $issue["name"] : ($_POST["slug"] ?? ""));
		return $issue;
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = (string)($_POST["action"] ?? "save");
		$issue = readIssue($maker);

		if ($issue["slug"] === "") {
			$notice = TemplateUtil::translate("admin.news-maker-need-name");
			$noticeKind = "bad";

		} elseif ($action === "withdraw") {
			// Out of the calendar and off the disk: the game stops using it.
			[$gone, $why] = $maker->withdraw($issue["slug"]);
			$admin->log("news.withdraw", $issue["slug"], $why);
			if ($gone) {
				$notice = TemplateUtil::translate("admin.news-maker-withdrawn");
			} else {
				$notice = TemplateUtil::translate("admin.news-maker-failed", ["%detail%" => $why]);
				$noticeKind = "bad";
			}
			$issue = $maker->issue($issue["slug"]) ?: $issue;

		} elseif ($action === "delete") {
			// Withdrawn first, so deleting the definition can never leave a
			// scheduled entry pointing at a file nobody can rebuild.
			$maker->withdraw($issue["slug"]);
			$dir = $maker->issuesDir();
			if ($dir !== false) @unlink($dir . "/" . $issue["slug"] . ".json");
			$admin->log("news.issue-delete", $issue["slug"]);
			header("Location: /admin/news_maker.php?deleted=1");
			return;

		} else {
			[$saved, $detail] = $maker->saveIssue($issue["slug"], $issue);
			if (!$saved) {
				$notice = TemplateUtil::translate("admin.news-maker-failed", ["%detail%" => $detail]);
				$noticeKind = "bad";
			} elseif ($action === "publish") {
				$regions = array_values(array_filter(
					(array)($_POST["regions"] ?? []),
					function ($r) { return isset(NewsMakerUtil::REGION_LANGUAGE[strtolower($r)]); }
				));
				if ($regions === []) {
					$notice = TemplateUtil::translate("admin.news-maker-need-region");
					$noticeKind = "bad";
				} else {
					[$results, $log] = $maker->publish($issue, $regions);
					// Scheduled only for the regions that actually built:
					// a calendar entry pointing at a file that is not there
					// is a run that warns and skips every day.
					$good = [];
					foreach ((array)$results as $region => $one) {
						if ($one[0]) $good[] = $region;
					}
					if ($good !== []) {
						[$sched, $why] = $maker->setScheduled($issue["slug"], $issue["date"], $good);
						if (!$sched) $log .= "\nschedule: " . $why;
					}
					if (!is_array($results) || $results === []) {
						$notice = TemplateUtil::translate("admin.news-maker-failed", ["%detail%" => (string)$log]);
						$noticeKind = "bad";
						$results = null;
					} else {
						$good = 0;
						foreach ($results as $one) { if ($one[0]) $good++; }
						$admin->log("news.publish", $issue["slug"], $good . "/" . count($results));
						$notice = TemplateUtil::translate("admin.news-maker-published",
							["%good%" => $good, "%total%" => count($results)]);
						$noticeKind = $good === count($results) ? "ok" : "bad";
					}
				}
			} else {
				$admin->log("news.issue-save", $issue["slug"]);
				$notice = TemplateUtil::translate("admin.news-maker-saved");
			}
		}

		if ($notice !== null || $results !== null) {
			echo TemplateUtil::render("admin/news_maker_edit", [
				"notice" => $notice, "notice_kind" => $noticeKind,
				"issue" => $issue, "results" => $results, "log" => $log,
			] + makerOptions($maker));
			return;
		}
	}

	// Everything the form needs to offer, read from the toolchain itself.
	function makerOptions($maker) {
		return [
			"languages" => NewsMakerUtil::LANGUAGES,
			"regions" => array_keys(NewsMakerUtil::REGION_LANGUAGE),
			"templates" => $maker->templates(),
			"minigames" => $maker->minigames(),
			"categories" => $maker->rankingCategories(),
			"missing" => $maker->missing(),
			"tool" => $maker->toolVersion(),
			"schedule_writable" => $maker->scheduleWritable(),
			"schedule_path" => $maker->schedulePath(),
		];
	}

	if (isset($_GET["issue"])) {
		$issue = $maker->issue($_GET["issue"]);
		if ($issue === null) {
			http_response_code(404);
			return;
		}
		echo TemplateUtil::render("admin/news_maker_edit", [
			"notice" => null, "notice_kind" => "ok",
			"issue" => $issue, "results" => null, "log" => "",
		] + makerOptions($maker));
		return;
	}

	if (isset($_GET["new"])) {
		$templates = $maker->templates();
		echo TemplateUtil::render("admin/news_maker_edit", [
			"notice" => null, "notice_kind" => "ok",
			"issue" => [
				"slug" => "", "name" => "", "message" => [], "date" => "",
				"template" => $templates ? $templates[0] : "",
				"minigame" => "", "rankings" => ["", "", ""],
				"headline" => [], "body" => [],
			],
			"results" => null, "log" => "",
		] + makerOptions($maker));
		return;
	}

	if (isset($_GET["deleted"])) $notice = TemplateUtil::translate("admin.news-maker-deleted");

	echo TemplateUtil::render("admin/news_maker", [
		"notice" => $notice, "notice_kind" => $noticeKind,
		"issues" => $maker->issues(),
		"missing" => $maker->missing(),
		"tool" => $maker->toolVersion(),
	]);
