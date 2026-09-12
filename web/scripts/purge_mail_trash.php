<?php
// SPDX-License-Identifier: MIT
// Esvazia a lixeira de correio depois da janela de retenção.
//
// Apagar, tanto no Mobile Trainer quanto no site, nunca destrói: este é o
// único lugar do sistema que remove correspondência de vez, e a janela de
// retenção é a rede de segurança inteira.
//
// Desde que o armazenamento passou para o Dovecot há DUAS formas de estar na
// lixeira, uma por ator:
//
//   o JOGO apaga com DELE, e o Dovecot só marca ($DeletedByGame,
//   pop3_deleted_flag) -- a mensagem fica na INBOX, escondida das sessões
//   POP3 seguintes;
//   o SITE apaga movendo para a pasta Trash, que o POP3 não serve.
//
// Por isso este job tem dois passos. O primeiro move as marcadas para a
// Trash, mantendo a marca (é ela que faz o site continuar dizendo "apagada
// pelo jogo"). Isso não é arrumação: é o que dá a elas uma DATA de exclusão.
// A data que o Dovecot guarda é a de gravação na pasta, então enquanto a
// mensagem ficar na INBOX a única data disponível é a da chegada -- e uma
// carta que passou quarenta dias na caixa antes de ser apagada seria expurgada
// no mesmo dia, sem retenção nenhuma.
//
// Roda pelo reon-mail-trash-purge.timer (diário).

require_once(__DIR__ . "/../classes/DBUtil.php");
require_once(__DIR__ . "/../classes/MailUtil.php");
require_once(__DIR__ . "/../classes/MailStoreUtil.php");

$days = MailUtil::TRASH_RETENTION_DAYS;
$db = DBUtil::getInstance()->getDB();

$res = $db->query("select dion_email_local from sys_users
                   where dion_email_local is not null and dion_email_local <> ''
                   order by id");

$movidas = 0;
$expurgadas = 0;
$contas = 0;

while ($u = $res->fetch_assoc()) {
	$caixa = $u["dion_email_local"];
	$contas++;

	// Passo 1: as que o jogo apagou saem da INBOX para a Trash, com a marca
	// junto, e o relógio da retenção começa a contar a partir daí.
	$marcadas = [];
	foreach (MailStoreUtil::rows($caixa, "INBOX") as $r) {
		if ($r["deleted_by"] === "game") $marcadas[] = $r["id"];
	}
	if (!empty($marcadas)) {
		$movidas += MailStoreUtil::moveToTrash($caixa, $marcadas);
	}

	// Passo 2: o que está na Trash há mais tempo que a retenção vai embora.
	$expurgadas += MailStoreUtil::purgeOlderThan($caixa, $days);
}

echo date("c") . " {$contas} conta(s): {$movidas} movida(s) para a lixeira, "
	. "{$expurgadas} expurgada(s) (retencao: {$days} dias)\n";
