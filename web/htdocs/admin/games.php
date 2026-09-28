<?php
	require_once("../../classes/TemplateUtil.php");
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AdminUtil.php");
	session_start();

	AdminUtil::guard();

	// The unified "Game Content" entry point: pick a game, then pick a
	// content type. Owner's request, 2026-09-28, replacing separate
	// top-level nav items for each game's pages (News, News Maker,
	// Mobile Stadium, Mobile Stadium Replays, Mobile Trainer) with one.
	//
	// Mobile Trainer has exactly one admin surface today
	// (TrainerPageUtil-backed trainer.php, which already lists every
	// page across every game code under web/htdocs/01/) -- so its card
	// goes straight there instead of through an intermediate one-item
	// list, the same way clicking a game with only one thing to manage
	// should not require an extra click.
	$jogo = strtolower((string)($_GET["game"] ?? ""));
	if ($jogo === "trainer") {
		header("Location: /admin/trainer.php");
		return;
	}

	if ($jogo !== "crystal" && $jogo !== "gbwars") {
		echo TemplateUtil::render("admin/games", ["game" => null]);
		return;
	}

	echo TemplateUtil::render("admin/games", ["game" => $jogo]);
