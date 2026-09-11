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
			// Mês e dia vêm separados; a data é remontada aqui, para o resto
			// do sistema continuar vendo um "MM-DD" só.
			"date" => (string)NewsMakerUtil::composeDate(
				$_POST["date_month"] ?? "", $_POST["date_day"] ?? ""),
			"rankings" => [],
			"headline" => [],
			"body" => [],
		];
		foreach ([0, 1, 2] as $slot) {
			$issue["rankings"][] = (string)($_POST["ranking"][$slot] ?? "");
		}

		// Um prêmio por slot do minijogo escolhido, na ordem em que o script
		// chega neles. Slot vazio ou ausente significa "o que o minijogo já
		// dava" -- guardar o padrão explicitamente faria a edição envelhecer
		// mal se o minijogo mudasse de prêmio numa atualização da toolchain.
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

	// "Publicar agora" põe no ar hoje uma edição que já existe e já foi
	// compilada. Mora na lista, e não no editor: no editor ela convidava a
	// publicar um formulário em branco -- o aviso de confirmação aparecia
	// antes de qualquer validação, avisando que a edição ficaria imutável,
	// sobre uma edição que ainda não tinha nada dentro.
	//
	// Daqui vem só o slug. A definição sai do disco e as regiões saem do que
	// já está compilado, e não de caixas marcadas num formulário que esta
	// tela não tem.
	//
	// A data é reescrita para hoje, e não ignorada: o agendador escolhe pela
	// data, e mandar ir ao ar sem mexer no calendário deixaria a linha
	// dizendo uma data e o jogo servindo outra.
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

		// O agendador roda pelo mesmo auxiliar que a página de serviços usa --
		// nada de privilégio novo. Se ele não estiver autorizado, a edição
		// fica compilada e agendada para hoje e a tela diz isso, em vez de
		// fingir que foi ao ar.
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

		// Sorteia o que ficou como aleatório e recusa repetição. Feito antes
		// de salvar, para a definição guardar o que de fato saiu -- sortear
		// de novo a cada build daria edições diferentes com o mesmo nome.
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
			// Recusa no servidor, e não só botão escondido na tela. Uma edição
			// que já foi ao ar é imutável: tirar do ar e apagar continuam
			// valendo, gravar e publicar por cima não.
			$notice = TemplateUtil::translate("admin.news-maker-locked");
			$noticeKind = "bad";
			$issue = $maker->issue($issue["slug"]) ?: $issue;

		} elseif (($problems = ($action === "delete" || $action === "withdraw" ? [] : $maker->checkText($issue))) !== []) {
			// Recusado, com o trecho culpado. O montador aceitaria calado e o
			// texto sairia da caixa na tela do console.
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
			&& ($unknown = $maker->checkPrizes($issue)) !== []
		) {
			// O montador recusaria um nome de item inexistente, mas só na
			// hora de compilar e com a mensagem dele; aqui a recusa diz qual
			// prêmio e antes de gravar.
			$notice = TemplateUtil::translate("admin.news-maker-bad-prize")
				. " " . implode(" · ", array_slice($unknown, 0, 6));
			$noticeKind = "bad";
		} elseif (!$resolved && $action !== "delete" && $action !== "withdraw") {
			$notice = TemplateUtil::translate("admin.news-maker-" . explode(":", $rankings)[0]);
			$noticeKind = "bad";

		} elseif ($action === "withdraw") {
			// As regiões são lidas ANTES de retirar: retirar apaga os arquivos
			// compilados, e é deles que sai a lista.
			$affected = array_merge(
				$maker->builtRegions($issue["slug"]),
				$maker->scheduledRegions($issue["slug"]));

			// Out of the calendar and off the disk: the game stops using it.
			[$gone, $why] = $maker->withdraw($issue["slug"]);
			$admin->log("news.withdraw", $issue["slug"], $why);
			if ($gone) {
				// E a oficial volta no lugar, aqui e agora. Tirar do calendário
				// só impede a próxima rodada de reaplicar: a linha custom
				// continuaria servindo esta edição até a notícia vanilla girar
				// a região, o que pode levar um mês.
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
				// O formulário marca idiomas; cada um vira as regiões que o
				// consomem. Inglês são três pastas, e escrever nas três é o
				// que o agendador precisa -- o que não precisa é de três
				// cliques para o mesmo arquivo.
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
					// Recusado antes de compilar: uma região sem o texto dela
					// não sairia vazia, sairia com o texto de 2002 que veio no
					// template.
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

						// Publicar compila e agenda pela data da edição. Quem
						// roda o agendador é "publicar agora", na lista: aqui
						// não se sabe se a data já chegou, e disparar a rodada
						// para uma edição marcada para dezembro não adianta
						// nada além de gastar um ciclo.
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
