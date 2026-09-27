<?php
	// SPDX-License-Identifier: MIT
	//
	// Importa distribuições do Mobile Stadium a partir de pares
	// <slug>.bin (payload cru, 0xFFE bytes) + <slug>.json (metadados),
	// no formato combinado com a sessão "PKHeX Linux Port" em 27/09/2026.
	// Ver docs/mobile-stadium/spec.md.
	//
	// Uso:
	//   php import_stadium_distribution.php <caminho.json>
	//   php import_stadium_distribution.php --dir <diretório>   (recursivo, todo *.json)
	//
	// Não ativa nada sozinho: uma distribuição importada nasce inativa
	// (StadiumUtil grava com active=0 por padrão). Ativar é decisão de quem
	// olha o painel -- /admin/stadium.php -- não deste script.
	require_once(__DIR__."/../web/classes/StadiumUtil.php");

	function importarUm($jsonPath) {
		if (!is_file($jsonPath)) {
			return "não é arquivo: $jsonPath";
		}
		$meta = json_decode(file_get_contents($jsonPath), true);
		if (!is_array($meta)) {
			return "$jsonPath: JSON inválido";
		}

		$obrigatorios = ["game_region", "file_id", "slug"];
		foreach ($obrigatorios as $campo) {
			if (!isset($meta[$campo]) || $meta[$campo] === "") {
				return "$jsonPath: falta o campo obrigatório '$campo'";
			}
		}

		$binPath = preg_replace('/\.json$/i', '.bin', $jsonPath);
		if ($binPath === $jsonPath || !is_file($binPath)) {
			return "$jsonPath: não achei o .bin ao lado ($binPath)";
		}

		$region = strtolower(trim((string)$meta["game_region"]));
		if (!in_array($region, StadiumUtil::REGIONS, true)) {
			return "$jsonPath: game_region '$region' desconhecida";
		}

		$fileIdHex = trim((string)$meta["file_id"]);
		if (!preg_match('/^[0-9a-fA-F]{32}$/', $fileIdHex)) {
			return "$jsonPath: file_id deve ser 32 caracteres hex (16 bytes), veio '$fileIdHex'";
		}
		$fileId = hex2bin($fileIdHex);

		$payload = file_get_contents($binPath);
		if ($payload === false) {
			return "$jsonPath: não consegui ler $binPath";
		}

		$cost = array_key_exists("cost", $meta) ? $meta["cost"] : 0;
		if ($cost !== null) $cost = (int)$cost;

		$slug = (string)$meta["slug"];
		$title = isset($meta["title"]) ? (string)$meta["title"] : null;
		$specVersion = isset($meta["spec_version"]) ? (string)$meta["spec_version"] : null;

		$erro = StadiumUtil::store($region, $fileId, $payload, $cost, $slug, $title, $specVersion);
		if ($erro !== "") {
			return "$jsonPath: recusado -- $erro";
		}

		// A agenda vem por último e é opcional: se ausente, a linha fica
		// com o padrão FFx6 ("sempre") que a migração já grava. Só a
		// atualizamos se o JSON trouxer algo diferente disso -- e mesmo
		// assim, com aviso, porque a especificação só rastreou o caso
		// "sempre" ponta a ponta (docs/mobile-stadium/spec.md §7).
		if (isset($meta["schedule"]) && is_array($meta["schedule"])) {
			$s = $meta["schedule"];
			$necessarios = ["first_day", "last_day", "start_hhmm", "end_hhmm"];
			$completo = true;
			foreach ($necessarios as $campo) {
				if (!isset($s[$campo])) { $completo = false; break; }
			}
			if ($completo) {
				$inicioHex = str_pad(strtolower((string)$s["start_hhmm"]), 4, "0", STR_PAD_LEFT);
				$fimHex = str_pad(strtolower((string)$s["end_hhmm"]), 4, "0", STR_PAD_LEFT);
				if (preg_match('/^[0-9a-f]{4}$/', $inicioHex) && preg_match('/^[0-9a-f]{4}$/', $fimHex)) {
					$agenda = chr((int)$s["first_day"] & 0xFF) . chr((int)$s["last_day"] & 0xFF)
						. hex2bin($inicioHex) . hex2bin($fimHex);
					if (strlen($agenda) === 6 && $agenda !== "\xFF\xFF\xFF\xFF\xFF\xFF") {
						fwrite(STDERR, "$jsonPath: agenda diferente de 'sempre' -- não exercitada ponta a ponta pela especificação (docs/mobile-stadium/spec.md §7). Gravando mesmo assim.\n");
						$db = DBUtil::getInstance()->getDB();
						$stmt = $db->prepare(
							"update bxt_stadium_distributions set schedule = ?
							  where game_region = ? and slug = ? order by id desc limit 1");
						$stmt->bind_param("sss", $agenda, $region, $slug);
						$stmt->execute();
					}
				}
			}
		}

		return "";
	}

	$args = array_slice($argv, 1);
	if (count($args) === 0) {
		fwrite(STDERR, "uso: php import_stadium_distribution.php <caminho.json>\n");
		fwrite(STDERR, "  ou: php import_stadium_distribution.php --dir <diretório>\n");
		exit(1);
	}

	$arquivos = [];
	if ($args[0] === "--dir") {
		$dir = $args[1] ?? null;
		if ($dir === null || !is_dir($dir)) {
			fwrite(STDERR, "diretório inválido: " . ($dir ?? "(nenhum)") . "\n");
			exit(1);
		}
		$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($iter as $f) {
			if (strtolower($f->getExtension()) === "json") $arquivos[] = $f->getPathname();
		}
		sort($arquivos);
	} else {
		$arquivos = $args;
	}

	if (count($arquivos) === 0) {
		echo "nada para importar.\n";
		exit(0);
	}

	$ok = 0; $falhas = 0;
	foreach ($arquivos as $jsonPath) {
		$erro = importarUm($jsonPath);
		if ($erro === "") {
			echo "OK   $jsonPath\n";
			$ok++;
		} else {
			echo "FALHA $erro\n";
			$falhas++;
		}
	}
	echo "\n$ok importada(s), $falhas falha(s). Nada foi ativado -- use /admin/stadium.php.\n";
	exit($falhas > 0 ? 1 : 0);
