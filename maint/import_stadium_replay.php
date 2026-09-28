<?php
	// SPDX-License-Identifier: MIT
	//
	// Imports single Mobile Stadium replay records into the library
	// (bxt_stadium_replays), from <slug>.bin (raw record, exactly 0x480
	// JP / 0x490 western bytes, own P3 trailer included) + <slug>.json
	// (metadata) pairs -- the single-replay counterpart of
	// import_stadium_distribution.php, added 2026-09-28 so a distribution
	// can be composed from replays picked in the admin panel instead of
	// requiring one already-assembled 3-battle block per distribution. See
	// docs/mobile-stadium/spec.md and StadiumUtil::composePayload().
	//
	// Usage:
	//   php import_stadium_replay.php <path.json>
	//   php import_stadium_replay.php --dir <directory>   (recursive, every *.json)
	//
	// Only fills the library -- it does not create or touch any
	// bxt_stadium_distributions row. Composing a distribution out of what
	// this imports is a decision for whoever is looking at the admin
	// panel, not for this script.
	require_once(__DIR__."/../web/classes/StadiumUtil.php");

	function importOneReplay($jsonPath) {
		if (!is_file($jsonPath)) {
			return "not a file: $jsonPath";
		}
		$meta = json_decode(file_get_contents($jsonPath), true);
		if (!is_array($meta)) {
			return "$jsonPath: invalid JSON";
		}

		$required = ["format", "label"];
		foreach ($required as $field) {
			if (!isset($meta[$field]) || $meta[$field] === "") {
				return "$jsonPath: missing required field '$field'";
			}
		}

		$binPath = preg_replace('/\.json$/i', '.bin', $jsonPath);
		if ($binPath === $jsonPath || !is_file($binPath)) {
			return "$jsonPath: could not find the matching .bin ($binPath)";
		}

		$format = strtolower(trim((string)$meta["format"]));
		if ($format !== "j" && $format !== "w") {
			return "$jsonPath: format must be 'j' (Japanese) or 'w' (western), got '$format'";
		}

		$record = file_get_contents($binPath);
		if ($record === false) {
			return "$jsonPath: could not read $binPath";
		}

		$label = (string)$meta["label"];
		$sourceNote = isset($meta["source_note"]) ? (string)$meta["source_note"] : null;

		$erro = StadiumUtil::storeReplay($format, $record, $label, $sourceNote);
		if ($erro !== "") {
			return "$jsonPath: refused -- $erro";
		}
		return "";
	}

	$args = array_slice($argv, 1);
	if (count($args) === 0) {
		fwrite(STDERR, "usage: php import_stadium_replay.php <path.json>\n");
		fwrite(STDERR, "   or: php import_stadium_replay.php --dir <directory>\n");
		exit(1);
	}

	$files = [];
	if ($args[0] === "--dir") {
		$dir = $args[1] ?? null;
		if ($dir === null || !is_dir($dir)) {
			fwrite(STDERR, "invalid directory: " . ($dir ?? "(none)") . "\n");
			exit(1);
		}
		$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($iter as $f) {
			if (strtolower($f->getExtension()) === "json") $files[] = $f->getPathname();
		}
		sort($files);
	} else {
		$files = $args;
	}

	if (count($files) === 0) {
		echo "nothing to import.\n";
		exit(0);
	}

	$ok = 0; $failed = 0;
	foreach ($files as $jsonPath) {
		$erro = importOneReplay($jsonPath);
		if ($erro === "") {
			echo "OK     $jsonPath\n";
			$ok++;
		} else {
			echo "FAILED $erro\n";
			$failed++;
		}
	}
	echo "\n$ok imported, $failed failed. Nothing was composed into a distribution -- use /admin/stadium_compose.php.\n";
	exit($failed > 0 ? 1 : 0);
