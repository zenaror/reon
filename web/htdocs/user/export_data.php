<?php
	require_once("../../classes/SessionUtil.php");
	require_once("../../classes/AccountDataUtil.php");
	session_start();

	// Tudo o que o servidor guarda sobre a conta, num arquivo só.
	//
	// GET, e sem token: é leitura dos próprios dados de quem já está na
	// sessão, e não muda nada. Um link que outro site induza a pessoa a
	// clicar faz o navegador dela baixar o arquivo dela mesma -- o outro
	// site não vê o conteúdo, porque não consegue ler a resposta de outra
	// origem.
	if (!SessionUtil::getInstance()->isSessionActive()) {
		header("Location: /index.php");
		exit;
	}

	$dados = AccountDataUtil::getInstance()->export($_SESSION["user_id"]);
	if ($dados === null) {
		http_response_code(404);
		exit;
	}

	// JSON_UNESCAPED_UNICODE para o japonês das mensagens sair legível em vez
	// de virar \uXXXX: o arquivo é para a pessoa ler, não para uma máquina
	// consumir. JSON_INVALID_UTF8_SUBSTITUTE porque corpo de correio de jogo
	// é binário e nem sempre é UTF-8 válido -- sem isto a codificação falha
	// inteira e a pessoa recebe um arquivo vazio, sem explicação.
	$json = json_encode(
		$dados,
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
	);
	if ($json === false) {
		http_response_code(500);
		exit;
	}

	$nome = "reon-" . preg_replace('/[^A-Za-z0-9_.-]/', "", (string)$dados["conta"]["username"])
		. "-" . gmdate("Y-m-d") . ".json";

	header("Content-Type: application/json; charset=utf-8");
	header('Content-Disposition: attachment; filename="' . $nome . '"');
	header("Content-Length: " . strlen($json));
	// O arquivo tem o correio inteiro da pessoa: não pode ficar em cache de
	// proxy nem no disco do navegador depois que ela sai.
	header("Cache-Control: no-store");

	print $json;
