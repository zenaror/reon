<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/CsrfUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/NotificationUtil.php");
	session_start();

	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /login.php");
		return;
	}

	$userId = $_SESSION["user_id"];
	$notify = NotificationUtil::getInstance();

	// Limpar é POST, e não um link: um GET que destrói é seguido por
	// prefetch de navegador, por crawler e por <img> alheia. Mesma regra das
	// ações da caixa de correio.
	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		CsrfUtil::check();
		if (($_POST["action"] ?? "") === "clear") $notify->clearForUser($userId);
		// Redireciona depois do POST para que um F5 não repita a ação.
		header("Location: /user/notifications.php");
		return;
	}

	$per = NotificationUtil::PAGE_SIZE;
	$page = max(1, (int)($_GET["page"] ?? 1));
	$total = $notify->countForUser($userId);
	$pages = max(1, (int)ceil($total / $per));
	if ($page > $pages) $page = $pages;

	$items = $notify->listForUser($userId, $per, ($page - 1) * $per);

	// The unread marks are read out of the rows before they are cleared, so
	// this page can still show which ones were new when it was opened. Only
	// the first page clears them: paging back through old history is not the
	// same as having seen what just arrived.
	if ($page === 1) $notify->markAllRead($userId);

	echo TemplateUtil::render("/user/notifications", [
		"items" => $items,
		"page" => $page,
		"pages" => $pages,
		"total" => $total,
	]);
