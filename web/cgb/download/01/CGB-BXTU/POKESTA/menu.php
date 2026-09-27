<?php
	// SPDX-License-Identifier: MIT
	require_once(CORE_PATH."/pokemon/stadium.php");

	$menu = get_stadium_menu("u");
	if ($menu === null) {
		http_response_code(404);
	} else {
		header("Content-Type: application/octet-stream");
		print $menu;
	}
?>
