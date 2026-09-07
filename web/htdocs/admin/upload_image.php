<?php
	require_once("../../classes/SessionUtil.php");
	session_start();

	header("Content-Type: application/json");

	if (!SessionUtil::getInstance()->isAdmin()) {
		http_response_code(404);
		return;
	}

	if ($_SERVER["REQUEST_METHOD"] !== "POST" || !isset($_FILES["image"])) {
		http_response_code(400);
		echo json_encode(["error" => "no-file"]);
		return;
	}

	$file = $_FILES["image"];
	if ($file["error"] !== UPLOAD_ERR_OK) {
		http_response_code(400);
		echo json_encode(["error" => "upload-failed"]);
		return;
	}

	if ($file["size"] > 4 * 1024 * 1024) {
		http_response_code(400);
		echo json_encode(["error" => "too-large"]);
		return;
	}

	// Extension comes from the sniffed MIME type, never from the uploaded
	// filename -- the client-supplied name is not trusted for anything.
	$allowed = [
		"image/png" => "png",
		"image/jpeg" => "jpg",
		"image/gif" => "gif",
		"image/webp" => "webp",
	];
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
	if (!isset($allowed[$mime])) {
		http_response_code(400);
		echo json_encode(["error" => "bad-type"]);
		return;
	}

	$dir = __DIR__."/../images/news";
	if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
		http_response_code(500);
		echo json_encode(["error" => "mkdir-failed"]);
		return;
	}

	$name = date("Ymd")."-".bin2hex(random_bytes(8)).".".$allowed[$mime];
	if (!move_uploaded_file($file["tmp_name"], $dir."/".$name)) {
		http_response_code(500);
		echo json_encode(["error" => "move-failed"]);
		return;
	}

	echo json_encode(["url" => "/images/news/".$name]);
