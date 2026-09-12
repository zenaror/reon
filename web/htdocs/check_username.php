<?php
	require_once("../classes/UserUtil.php");
	session_start();

	// Live availability check for the signup form. Gated behind a valid pending
	// signup (id + key) on purpose: a REON username is also an e-mail address,
	// so an open endpoint here would let anyone enumerate which addresses exist.
	// Only someone already holding a signup link can probe.
	header("Content-Type: application/json");

	$id = isset($_GET["id"]) ? $_GET["id"] : null;
	$key = isset($_GET["key"]) ? $_GET["key"] : null;
	$name = isset($_GET["name"]) ? (string)$_GET["name"] : "";

	if ($id === null || $key === null) {
		http_response_code(400);
		echo json_encode(["error" => "missing-signup"]);
		return;
	}

	$signupEmail = UserUtil::getInstance()->verifySignupRequest($id, $key);
	if (!isset($signupEmail) || $signupEmail === "") {
		http_response_code(403);
		echo json_encode(["error" => "invalid-signup"]);
		return;
	}

	$user = UserUtil::getInstance();

	$wellFormed = strlen($name) >= UserUtil::USERNAME_MIN
	           && strlen($name) <= UserUtil::USERNAME_MAX
	           && (bool)preg_match("/^[a-z0-9]+$/", $name);
	$available = $wellFormed && $user->isUsernameAvailable($name);

	// The in-game address is derived, not chosen, and can differ from the first
	// 8 characters when that prefix is already taken -- so the form asks for it
	// rather than computing it client-side and risking a different answer.
	echo json_encode([
		"well_formed" => $wellFormed,
		"available" => $available,
		"dion_local" => $available ? $user->deriveDionLocal($name) : "",
	]);
