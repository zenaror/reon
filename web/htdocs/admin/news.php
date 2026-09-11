<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/NewsUtil.php");
	require_once("../../classes/AdminUtil.php");
	session_start();

	// The panel's single guard, rather than this page's own copy of the
	// check: a module that decides for itself is a module that one day
	// decides differently.
	AdminUtil::guard();

	$news = NewsUtil::getInstance();
	$admin = AdminUtil::getInstance();

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		$action = isset($_POST["action"]) ? $_POST["action"] : "";

		if ($action === "delete" && isset($_POST["id"])) {
			$post = $news->getById($_POST["id"]);
			$news->delete($_POST["id"]);
			$admin->log("news.delete", $post ? $post["title"] : $_POST["id"]);
			header("Location: /admin/news.php");
			return;
		}

		if ($action === "save") {
			$title = isset($_POST["title"]) ? trim($_POST["title"]) : "";
			$body = isset($_POST["body"]) ? $_POST["body"] : "";
			$published = isset($_POST["published"]);

			if ($title === "") {
				echo TemplateUtil::render("admin/news_edit", [
					"post" => ["id" => $_POST["id"] ?? null, "title" => $title, "body" => $body, "published_at" => null],
					"error" => "title-required",
				]);
				return;
			}

			if (isset($_POST["id"]) && $_POST["id"] !== "") {
				$news->update($_POST["id"], $title, $body, $published);
				$admin->log($published ? "news.publish" : "news.update", $title);
			} else {
				$news->create($title, $body, $published);
				$admin->log($published ? "news.publish" : "news.create", $title);
			}
			header("Location: /admin/news.php");
			return;
		}

		http_response_code(400);
		return;
	}

	$action = isset($_GET["action"]) ? $_GET["action"] : "list";

	if ($action === "new") {
		echo TemplateUtil::render("admin/news_edit", [
			"post" => null,
			"error" => null,
		]);
		return;
	}

	if ($action === "edit" && isset($_GET["id"])) {
		$post = $news->getById($_GET["id"]);
		if ($post === null) {
			http_response_code(404);
			return;
		}
		echo TemplateUtil::render("admin/news_edit", [
			"post" => $post,
			"error" => null,
		]);
		return;
	}

	echo TemplateUtil::render("admin/news_list", [
		"posts" => $news->getAll(),
	]);
