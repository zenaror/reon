<?php
	require_once("../classes/TemplateUtil.php");
	require_once("../classes/NewsUtil.php");
	require_once("../classes/ServiceStatusUtil.php");
	session_start();

	define("NEWS_PER_PAGE", 10);

	$news = NewsUtil::getInstance();
	// The shared portal sidebar shows service status, so every page using
	// that layout has to provide it.
	$services = ServiceStatusUtil::getInstance()->getAll();

	if (isset($_GET["slug"]) && $_GET["slug"] !== "") {
		$post = $news->getBySlug($_GET["slug"]);
		if ($post === null) {
			http_response_code(404);
			echo TemplateUtil::render("news_post", ["post" => null, "html" => null, "services" => $services]);
			return;
		}
		echo TemplateUtil::render("news_post", [
			"post" => $post,
			"html" => $news->render($post["body"]),
			"services" => $services,
		]);
		return;
	}

	$page = isset($_GET["page"]) ? max(1, (int)$_GET["page"]) : 1;
	$total = $news->countPublished();
	$pages = max(1, (int)ceil($total / NEWS_PER_PAGE));
	if ($page > $pages) $page = $pages;

	echo TemplateUtil::render("news", [
		"posts" => $news->getPublished(NEWS_PER_PAGE, ($page - 1) * NEWS_PER_PAGE),
		"page" => $page,
		"pages" => $pages,
		"services" => $services,
	]);
