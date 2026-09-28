<?php
	// SPDX-License-Identifier: MIT
	//
	// Imports Mobile Stadium distributions from <slug>.bin (raw payload,
	// 0xFFE bytes) + <slug>.json (metadata) pairs, in the format agreed
	// with the "PKHeX Linux Port" session on 2026-09-27. See
	// docs/mobile-stadium/spec.md.
	//
	// Usage:
	//   php import_stadium_distribution.php <path.json>
	//   php import_stadium_distribution.php --dir <directory>   (recursive, every *.json)
	//
	// Never activates anything on its own: an imported distribution is born
	// inactive (StadiumUtil writes it with active=0 by default). Activating
	// is a decision for whoever is looking at the panel -- /admin/stadium.php
	// -- not for this script.
	require_once(__DIR__."/../web/classes/StadiumUtil.php");

	function importOne($jsonPath) {
		if (!is_file($jsonPath)) {
			return "not a file: $jsonPath";
		}
		$meta = json_decode(file_get_contents($jsonPath), true);
		if (!is_array($meta)) {
			return "$jsonPath: invalid JSON";
		}

		$required = ["game_region", "file_id", "slug"];
		foreach ($required as $field) {
			if (!isset($meta[$field]) || $meta[$field] === "") {
				return "$jsonPath: missing required field '$field'";
			}
		}

		$binPath = preg_replace('/\.json$/i', '.bin', $jsonPath);
		if ($binPath === $jsonPath || !is_file($binPath)) {
			return "$jsonPath: could not find the matching .bin ($binPath)";
		}

		$region = strtolower(trim((string)$meta["game_region"]));
		if (!in_array($region, StadiumUtil::REGIONS, true)) {
			return "$jsonPath: unknown game_region '$region'";
		}

		$fileIdHex = trim((string)$meta["file_id"]);
		if (!preg_match('/^[0-9a-fA-F]{32}$/', $fileIdHex)) {
			return "$jsonPath: file_id must be 32 hex characters (16 bytes), got '$fileIdHex'";
		}
		$fileId = hex2bin($fileIdHex);

		$payload = file_get_contents($binPath);
		if ($payload === false) {
			return "$jsonPath: could not read $binPath";
		}

		$cost = array_key_exists("cost", $meta) ? $meta["cost"] : 0;
		if ($cost !== null) $cost = (int)$cost;

		$slug = (string)$meta["slug"];
		$title = isset($meta["title"]) ? (string)$meta["title"] : null;
		$specVersion = isset($meta["spec_version"]) ? (string)$meta["spec_version"] : null;
		// Default false (official): this importer's whole purpose is
		// bringing in an already-assembled block that reproduces a real
		// historical distribution. A JSON that explicitly says otherwise
		// can still mark itself custom, but that is the exception, not
		// the rule -- composing custom content is StadiumUtil::composePayload()'s
		// job (the admin panel), not this importer's.
		$isCustom = array_key_exists("is_custom", $meta) ? (bool)$meta["is_custom"] : false;

		$erro = StadiumUtil::store($region, $fileId, $payload, $cost, $slug, $title, $specVersion, $isCustom);
		if ($erro !== "") {
			return "$jsonPath: refused -- $erro";
		}

		// The schedule comes last and is optional: if absent, the row
		// keeps the FFx6 ("always") default the migration already writes.
		// We only update it if the JSON brings something different -- and
		// even then, with a warning, because the specification only traced
		// the "always" case end to end (docs/mobile-stadium/spec.md §7).
		if (isset($meta["schedule"]) && is_array($meta["schedule"])) {
			$s = $meta["schedule"];
			$needed = ["first_day", "last_day", "start_hhmm", "end_hhmm"];
			$complete = true;
			foreach ($needed as $field) {
				if (!isset($s[$field])) { $complete = false; break; }
			}
			if ($complete) {
				$startHex = str_pad(strtolower((string)$s["start_hhmm"]), 4, "0", STR_PAD_LEFT);
				$endHex = str_pad(strtolower((string)$s["end_hhmm"]), 4, "0", STR_PAD_LEFT);
				if (preg_match('/^[0-9a-f]{4}$/', $startHex) && preg_match('/^[0-9a-f]{4}$/', $endHex)) {
					$schedule = chr((int)$s["first_day"] & 0xFF) . chr((int)$s["last_day"] & 0xFF)
						. hex2bin($startHex) . hex2bin($endHex);
					if (strlen($schedule) === 6 && $schedule !== "\xFF\xFF\xFF\xFF\xFF\xFF") {
						fwrite(STDERR, "$jsonPath: schedule differs from 'always' -- not exercised end to end by the specification (docs/mobile-stadium/spec.md §7). Writing it anyway.\n");
						$db = DBUtil::getInstance()->getDB();
						$stmt = $db->prepare(
							"update bxt_stadium_distributions set schedule = ?
							  where game_region = ? and slug = ? order by id desc limit 1");
						$stmt->bind_param("sss", $schedule, $region, $slug);
						$stmt->execute();
					}
				}
			}
		}

		return "";
	}

	$args = array_slice($argv, 1);
	if (count($args) === 0) {
		fwrite(STDERR, "usage: php import_stadium_distribution.php <path.json>\n");
		fwrite(STDERR, "   or: php import_stadium_distribution.php --dir <directory>\n");
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
		$erro = importOne($jsonPath);
		if ($erro === "") {
			echo "OK     $jsonPath\n";
			$ok++;
		} else {
			echo "FAILED $erro\n";
			$failed++;
		}
	}
	echo "\n$ok imported, $failed failed. Nothing was activated -- use /admin/stadium.php.\n";
	exit($failed > 0 ? 1 : 0);
