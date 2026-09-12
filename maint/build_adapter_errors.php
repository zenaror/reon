<?php
// SPDX-License-Identifier: MIT
//
// Monta web/data/adapter_errors.json a partir das duas fontes públicas que
// descrevem os erros do Mobile Adapter GB, e de mais nada. Rode de novo
// quando qualquer uma das duas mudar:
//
//     php maint/build_adapter_errors.php
//
// As duas fontes respondem perguntas diferentes, e é por isso que são duas:
//
//   ADAPTER_ERROR_CODES.MD   o que o código SIGNIFICA, em inglês. Cobre uma
//                            dúzia deles.
//   a planilha pública       o que cada JOGO ESCREVE na tela para aquele
//                            código, em japonês. Cobre 23 jogos.
//
// O jogador vê o texto japonês e não vê código nenhum; quem depura tem o
// código e não sabe o texto. A página junta os dois lados.
//
// O arquivo gerado é versionado de propósito: a página não pode depender de
// duas requisições externas para desenhar, e uma planilha que o dono não
// controla não pode derrubar uma página do site ao ficar fora do ar.

const SHEET = "https://docs.google.com/spreadsheets/d/1noSmA-ilQUWQlgPE9WEi8tGPvWt7DqLtkdLq4YyXC2Y/export?format=csv&gid=0";
const CODES = "https://raw.githubusercontent.com/Incineroar/MobileAdapterGB/refs/heads/master/ADAPTER_ERROR_CODES.MD";

// Nomes só onde o NOSSO código os afirma -- o prefixo de tabela de cada
// handler em web/cgb/ diz qual jogo é qual. O resto fica com o código do
// cartucho, que é o que se pode provar. Inventar vinte títulos japoneses
// seria enfeitar a página com chute.
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

// ------------------------------------------------- o que o código significa
$descriptions = [];
foreach (explode("\n", fetchText(CODES)) as $line) {
	// | 10-000  | Adapter is not connected |
	if (!preg_match('/^\|\s*([0-9]+-[0-9Xx]{3})\s*\|\s*(.+?)\s*\|?\s*$/', $line, $m)) continue;
	$descriptions[strtoupper($m[1])] = rtrim($m[2], " |");
}

// ------------------------------------------- o que cada jogo mostra na tela
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

	// Algumas linhas trazem um apelido na segunda linha da célula, tipo
	// "15-000\n(83-XXX)": o código é a primeira, o resto é nota.
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

// Um código descrito que a planilha não lista ainda merece uma linha: a
// descrição é o que quem depura procura, e escondê-la por falta de texto de
// tela seria perder a metade útil.
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

// A explicação amigável NÃO entra aqui. Ela é escrita à mão, um arquivo por
// idioma (web/data/adapter_error_notes.<lang>.json), e web/classes/
// AdapterErrorUtil.php a junta a este dado na hora de desenhar -- que é onde
// se sabe qual idioma a pessoa está lendo. Guardar os sete aqui multiplicaria
// por sete um arquivo de 400 KB para entregar um sétimo dele.

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

// Aviso útil: um código sem nota em inglês sai na página sem explicação.
$notes = json_decode((string)@file_get_contents(
	__DIR__ . "/../web/data/adapter_error_notes.en.json"), true) ?: [];
// As chaves "10", "11"... voltam do json_decode como INTEIRO, então a
// comparação estrita contra a string do prefixo nunca casava e isto acusava
// os 108 códigos de uma vez. Comparação por string dos dois lados.
$fam = array_map("strval", array_keys($notes["families"] ?? []));
$sem = array_values(array_filter(array_map(fn($r) => $r["code"], $rows),
	fn($c) => !in_array(explode("-", $c)[0], $fam, true)
	       && !isset($notes["codes"][$c])));
if ($sem) printf("  SEM nota em inglês: %s\n", implode(", ", $sem));
