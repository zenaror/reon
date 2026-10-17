<?php
// SPDX-License-Identifier: MIT
//
// Build web/data/adapter_errors.json from the two public error references. Regenerate with
// php maint/build_adapter_errors.php when either source changes. ADAPTER_ERROR_CODES.MD
// supplies English meanings; the public spreadsheet supplies Japanese screen text for 23
// games. Keep the generated data in Git so page availability does not depend on either
// external source.

const SHEET = "https://docs.google.com/spreadsheets/d/1noSmA-ilQUWQlgPE9WEi8tGPvWt7DqLtkdLq4YyXC2Y/export?format=csv&gid=0";
const CODES = "https://raw.githubusercontent.com/Incineroar/MobileAdapterGB/refs/heads/master/ADAPTER_ERROR_CODES.MD";

// Assign readable titles only where our handlers establish the game identity. Otherwise
// retain the cartridge code instead of inventing Japanese titles.
const NAMES = [
	"CGB-BXTJ" => "Pokémon Crystal",   // bxt_*, web/cgb/pokemon/
	"AGB-AMKJ" => "Mario Kart Super Circuit", // amk_*, web/cgb/mario_kart.php
	"AGB-AMOJ" => "Monopoly",          // amo_*, web/cgb/monopoly.php
	"AGB-AGTJ" => "Zen-Nihon GT Senshuken", // agt_*, web/cgb/zen_nihon.php
];

function fetchText($url) {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT => 60,
		CURLOPT_USERAGENT => "reon-build-adapter-errors",
	]);
	$body = curl_exec($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($body === false || $code !== 200) {
		fwrite(STDERR, "falhou ($code): $url\n");
		exit(1);
	}
	return $body;
}

// Error-code meanings.
$descriptions = [];
foreach (explode("\n", fetchText(CODES)) as $line) {
	// | 10-000  | Adapter is not connected |
	if (!preg_match('/^\|\s*([0-9]+-[0-9Xx]{3})\s*\|\s*(.+?)\s*\|?\s*$/', $line, $m)) continue;
	$descriptions[strtoupper($m[1])] = rtrim($m[2], " |");
}

// Cartridge screen messages
$csv = fetchText(SHEET);
$tmp = tmpfile();
fwrite($tmp, $csv);
rewind($tmp);

$head = fgetcsv($tmp);
$games = [];
foreach ($head as $i => $label) {
	$label = trim($label);
	if (preg_match('/^(CGB|AGB)-[A-Z0-9]{4}$/', $label)) $games[$i] = $label;
}

$rows = [];
while (($line = fgetcsv($tmp)) !== false) {
	$key = trim($line[0] ?? "");
	if ($key === "") continue;

	// Some cells include an alias on a second line, such as 15-000 and (83-XXX).
	// The first line is the code; retain the remainder as a note.
	$parts = preg_split('/\r?\n/', $key, 2);
	$code = strtoupper(trim($parts[0]));
	$note = isset($parts[1]) ? trim($parts[1], " ()\r\n") : "";
	if (!preg_match('/^[0-9]+-[0-9X]{3}$/', $code)) continue;

	$messages = [];
	foreach ($games as $i => $game) {
		$text = trim($line[$i] ?? "");
		if ($text !== "") $messages[$game] = $text;
	}
	if (!$messages && !isset($descriptions[$code])) continue;

	$rows[] = [
		"code" => $code,
		"alias" => $note,
		"description" => $descriptions[$code] ?? "",
		"messages" => $messages,
	];
}
fclose($tmp);

// Keep a documented error code even if the spreadsheet has no screen text: its meaning
// remains useful for debugging.
$seen = array_column($rows, "code");
foreach ($descriptions as $code => $text) {
	if (in_array($code, $seen, true)) continue;
	$rows[] = ["code" => $code, "alias" => "", "description" => $text, "messages" => []];
}

usort($rows, function ($a, $b) {
	[$an, $as] = array_pad(explode("-", $a["code"]), 2, "");
	[$bn, $bs] = array_pad(explode("-", $b["code"]), 2, "");
	return [(int)$an, $as] <=> [(int)$bn, $bs];
});

// Handwritten explanations belong in web/data/adapter_error_notes.<lang>.json.
// AdapterErrorUtil selects the reader language at rendering time rather than duplicating
// all translations in the generated dataset.

$out = [
	"generated" => gmdate("Y-m-d"),
	"sources" => ["descriptions" => CODES, "messages" => SHEET],
	"games" => array_map(function ($code) {
		return ["code" => $code, "name" => NAMES[$code] ?? ""];
	}, array_values($games)),
	"rows" => $rows,
];

$path = __DIR__ . "/../web/data/adapter_errors.json";
file_put_contents($path, json_encode($out,
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

printf("%s\n  %d jogos, %d códigos, %d com descrição upstream, %d mensagens\n",
	$path, count($out["games"]), count($rows),
	count(array_filter($rows, fn($r) => $r["description"] !== "")),
	array_sum(array_map(fn($r) => count($r["messages"]), $rows)));

// Warn when a code has no English explanation and would render without one.
$notes = json_decode((string)@file_get_contents(
	__DIR__ . "/../web/data/adapter_error_notes.en.json"), true) ?: [];
// json_decode returns keys such as 10 and 11 as integers. Compare string values on both
// sides; a strict integer/string comparison incorrectly flagged all 108 codes.
$fam = array_map("strval", array_keys($notes["families"] ?? []));
$sem = array_values(array_filter(array_map(fn($r) => $r["code"], $rows),
	fn($c) => !in_array(explode("-", $c)[0], $fam, true)
	       && !isset($notes["codes"][$c])));
if ($sem) printf("  SEM nota em inglês: %s\n", implode(", ", $sem));
