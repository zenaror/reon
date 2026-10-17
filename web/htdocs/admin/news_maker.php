<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	require_once("../../classes/NewsMakerUtil.php");
	require_once("../../classes/ServiceControlUtil.php");
	require_once("../../classes/DBUtil.php");
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
			// Recombine separate month/day inputs into the MM-DD value expected by the rest of the
			// system.
			"date" => (string)NewsMakerUtil::composeDate(
				$_POST["date_month"] ?? "", $_POST["date_day"] ?? ""),
			"rankings" => [],
			"headline" => [],
			"body" => [],
		];
		foreach ([0, 1, 2] as $slot) {
			$issue["rankings"][] = (string)($_POST["ranking"][$slot] ?? "");
		}

		// Use one reward per selected minigame slot in script order. An empty slot retains the
		// minigame default instead of storing a stale explicit default after a toolchain update.
		$issue["prizes"] = [];
		foreach ((array)($_POST["prize"] ?? []) as $slot => $item) {
			if (!ctype_digit((string)$slot)) continue;
			$issue["prizes"][(int)$slot] = trim((string)$item);
		}
		ksort($issue["prizes"]);
		$issue["prizes"] = array_values($issue["prizes"]);
		foreach (array_keys(NewsMakerUtil::LANGUAGES) as $language) {
			$issue["headline"][$language] = trim((string)($_POST["headline"][$language] ?? ""));
			$issue["message"][$language] = trim((string)($_POST["message"][$language] ?? ""));
			$issue["body"][$language] = (string)($_POST["body"][$language] ?? "");
		}
		$issue["slug"] = $maker->slug($issue["name"] !== "" ? $issue["name"] : ($_POST["slug"] ?? ""));
		return $issue;
	}

	// Publish now operates on an existing compiled edition from the list, not an unsaved
	// editor form. Load its definition and compiled regions from disk using only its slug.
	// Rewrite the schedule date to today so the calendar and served edition agree.
	function publishNow($maker, $admin, $slug) {
		$issue = $maker->issue($slug);
		if ($issue === null) {
			return [TemplateUtil::translate("admin.news-maker-failed",
				["%detail%" => "no-issue"]), "bad"];
		}
		if ($maker->isPublished($slug)) {
			return [TemplateUtil::translate("admin.news-maker-locked"), "bad"];
		}

		$regions = array_values(array_unique(array_merge(
			$maker->builtRegions($slug), $maker->scheduledRegions($slug))));
		if ($regions === []) {
			return [TemplateUtil::translate("admin.news-maker-publish-now-unbuilt"), "bad"];
		}

		$issue["date"] = (string)NewsMakerUtil::composeDate(date("n"), date("j"));
		[$saved, $detail] = $maker->saveIssue($slug, $issue);
		if (!$saved) {
			return [TemplateUtil::translate("admin.news-maker-failed",
				["%detail%" => $detail]), "bad"];
		}

		[$results] = $maker->publish($issue, $regions);
		$good = [];
		foreach ((array)$results as $region => $one) {
			if ($one[0]) $good[] = $region;
		}
		if ($good === []) {
			return [TemplateUtil::translate("admin.news-maker-failed",
				["%detail%" => "build"]), "bad"];
		}

		[$sched, $why] = $maker->setScheduled($slug, $issue["date"], $good, $issue["rankings"] ?? []);
		if (!$sched) {
			return [TemplateUtil::translate("admin.news-maker-failed",
				["%detail%" => $why]), "bad"];
		}

		// Run the scheduler through the existing service helper without new privileges. If
		// unavailable, leave the edition compiled and scheduled for today and report that it was
		// not immediately published.
		[$ran, $ranWhy] = ServiceControlUtil::getInstance()->act("reon-auto-schedule", "run");
		$admin->log("news.publish-now", $slug, $ranWhy);
		if (!$ran) {
			return [TemplateUtil::translate("admin.news-maker-run-failed",
				["%detail%" => $ranWhy]), "bad"];
		}
		return [TemplateUtil::translate("admin.news-maker-published-now",
			["%regions%" => strtoupper(implode(", ", $good))]), "ok"];
	}

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = (string)($_POST["action"] ?? "save");

		if ($action === "publish-now") {
			[$notice, $noticeKind] = publishNow($maker, $admin,
				(string)($_POST["slug"] ?? ""));
			echo TemplateUtil::render("admin/news_maker", [
				"notice" => $notice, "notice_kind" => $noticeKind,
				"issues" => $maker->issues(),
				"missing" => $maker->missing(),
				"tool" => $maker->toolVersion(),
			]);
			return;
		}

		$issue = readIssue($maker);

		// Resolve randomized categories and reject duplicates before saving so repeated builds
		// retain the same edition choices.
		[$resolved, $rankings] = $maker->resolveRankings($issue["rankings"]);
		if ($resolved) {
			$issue["rankings"] = $rankings;
		}

		if ($issue["slug"] === "") {
			$notice = TemplateUtil::translate("admin.news-maker-need-name");
			$noticeKind = "bad";
		} elseif (
			($action !== "delete" && $action !== "withdraw")
			&& $maker->isPublished($issue["slug"])
		) {
			// Enforce published-edition immutability on the server. Removing or deleting remains
			// allowed, but editing or overwriting publication does not.
			$notice = TemplateUtil::translate("admin.news-maker-locked");
			$noticeKind = "bad";
			$issue = $maker->issue($issue["slug"]) ?: $issue;

		} elseif (($problems = ($action === "delete" || $action === "withdraw" ? [] : $maker->checkText($issue))) !== []) {
			// Reject oversized text with the offending excerpt; the assembler would otherwise allow
			// display overflow.
			$lines = [];
			foreach (array_slice($problems, 0, 6) as $one) {
				$lines[] = TemplateUtil::translate("admin.news-maker-lang-" . $one["language"])
					. " · " . TemplateUtil::translate("admin.news-maker-" . $one["field"])
					. (isset($one["line"]) ? " " . $one["line"] : "")
					. ": " . $one["length"] . "/" . $one["limit"] . " — \"" . $one["text"] . "\"";
			}
			$notice = TemplateUtil::translate("admin.news-maker-too-long") . " " . implode(" · ", $lines);
			$noticeKind = "bad";
		} elseif (
			($action !== "delete" && $action !== "withdraw")
			&& $maker->brokenMinigame((string)($issue["minigame"] ?? "")) !== null
		) {
			// Reject an unsupported minigame before the user completes an edition and receives a raw
			// assembler error.
			$notice = TemplateUtil::translate("admin.news-maker-minigame-broken");
			$noticeKind = "bad";
		} elseif (
			($action !== "delete" && $action !== "withdraw")
			&& ($unknown = $maker->checkPrizes($issue)) !== []
		) {
			// Reject unknown item names before saving, with a reward-specific diagnostic rather than a
			// later assembler failure.
			$notice = TemplateUtil::translate("admin.news-maker-bad-prize")
				. " " . implode(" · ", array_slice($unknown, 0, 6));
			$noticeKind = "bad";
		} elseif (!$resolved && $action !== "delete" && $action !== "withdraw") {
			$notice = TemplateUtil::translate("admin.news-maker-" . explode(":", $rankings)[0]);
			$noticeKind = "bad";

		} elseif ($action === "withdraw") {
			// Read compiled regions before removal deletes the files used to determine that list.
			$affected = array_merge(
				$maker->builtRegions($issue["slug"]),
				$maker->scheduledRegions($issue["slug"]));

			// Out of the calendar and off the disk: the game stops using it.
			[$gone, $why] = $maker->withdraw($issue["slug"]);
			$admin->log("news.withdraw", $issue["slug"], $why);
			if ($gone) {
				// Immediately restore official content too. Calendar removal alone prevents future
				// scheduling but would leave the custom row serving this edition until the next vanilla
				// rotation.
				[$back] = $admin->restoreOfficialNews($affected);
				$admin->log("news.restore-official", $issue["slug"], (string)$back);
				$notice = TemplateUtil::translate("admin.news-maker-withdrawn")
					. " " . TemplateUtil::translate("admin.news-maker-restored",
						["%count%" => $back]);
			} else {
				$notice = TemplateUtil::translate("admin.news-maker-failed", ["%detail%" => $why]);
				$noticeKind = "bad";
			}
			$issue = $maker->issue($issue["slug"]) ?: $issue;

		} elseif ($action === "delete") {
			$affected = array_merge(
				$maker->builtRegions($issue["slug"]),
				$maker->scheduledRegions($issue["slug"]));

			// Withdrawn first, so deleting the definition can never leave a
			// scheduled entry pointing at a file nobody can rebuild.
			$maker->withdraw($issue["slug"]);
			$dir = $maker->issuesDir();
			if ($dir !== false) @unlink($dir . "/" . $issue["slug"] . ".json");
			[$back] = $admin->restoreOfficialNews($affected);
			$admin->log("news.issue-delete", $issue["slug"], "restored:" . $back);
			header("Location: /admin/news_maker.php?deleted=1");
			return;

		} else {
			[$saved, $detail] = $maker->saveIssue($issue["slug"], $issue);
			if (!$saved) {
				$notice = TemplateUtil::translate("admin.news-maker-failed", ["%detail%" => $detail]);
				$noticeKind = "bad";
			} elseif ($action === "publish") {
				// Expand each selected language to its consuming regions. English needs three destination
				// folders but only one editor choice.
				$targets = $maker->buildTargets();
				$regions = [];
				foreach ((array)($_POST["regions"] ?? []) as $language) {
					$language = strtolower((string)$language);
					if (!isset($targets[$language])) continue;
					foreach ($targets[$language] as $region) $regions[] = $region;
				}
				$regions = array_values(array_unique($regions));
				$gaps = $maker->checkBuildable($issue, $regions);

				if ($regions === []) {
					$notice = TemplateUtil::translate("admin.news-maker-need-region");
					$noticeKind = "bad";
				} elseif ($gaps !== []) {
					// Reject a missing region translation before compiling; otherwise the template original
					// 2002 article would be published.
					$lines = [];
					foreach ($gaps as $one) {
						$fields = [];
						foreach ($one["fields"] as $field) {
							$fields[] = TemplateUtil::translate("admin.news-maker-" . $field);
						}
						$lines[] = strtoupper($one["region"]) . " ("
							. TemplateUtil::translate("admin.news-maker-lang-" . $one["language"])
							. "): " . implode(", ", $fields);
					}
					$notice = TemplateUtil::translate("admin.news-maker-region-empty")
						. " " . implode(" · ", $lines);
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
						[$sched, $why] = $maker->setScheduled(
							$issue["slug"], $issue["date"], $good, $issue["rankings"]);
						if (!$sched) $log .= "\nschedule: " . $why;

						// Publish compiles and schedules for the edition date. Publish now on the list runs the
						// scheduler; triggering it here would waste a run for an edition scheduled in the future.
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
			"prize_items" => $maker->items(),
			"prizes_by_minigame" => $maker->prizesByMinigame(),
			"broken_minigames" => $maker->brokenMinigames(),
			"categories" => $maker->rankingCategories(),
			"missing" => $maker->missing(),
			"tool" => $maker->toolVersion(),
			"days_in_month" => NewsMakerUtil::DAYS_IN_MONTH,
			"region_language" => NewsMakerUtil::REGION_LANGUAGE,
			"targets" => $maker->buildTargets(),
			"headline_max" => NewsMakerUtil::HEADLINE_MAX,
			"message_max" => NewsMakerUtil::MESSAGE_MAX,
			"body_line_max" => NewsMakerUtil::BODY_LINE_MAX,
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
				"minigame" => "", "rankings" => ["", "", ""], "prizes" => [],
				"headline" => [], "body" => [], "exists" => false,
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
