<?php
// SPDX-License-Identifier: MIT
// Aplica as janelas de retenção dos registros do sistema.
//
// Existe porque prazo escrito e não aplicado é pior que prazo nenhum: a
// política de privacidade passou a prometer estes números em 25/09/2026, e uma
// promessa que o sistema não cumpre é uma declaração falsa, não uma intenção.
// Se você mudar um número aqui, mude na privacidade também -- são os mesmos
// números em dois lugares, e não há nada além deste comentário forçando isso.
//
// O que NÃO está aqui, de propósito:
//
//   o log do nginx, que a rotação já corta em 14 dias;
//   o journal do systemd, que é configuração e não SQL -- vive no
//     MaxRetentionSec do journald (30 dias), fora do alcance do PHP;
//   a lixeira do correio, que tem script próprio (purge_mail_trash.php),
//     porque apagar correspondência de vez merece um lugar só dela.
//
// Roda pelo reon-retention-purge.timer (diário).

require_once(__DIR__ . "/../classes/DBUtil.php");

// Os prazos, num lugar só. O motivo de cada um está ao lado porque "90" sem
// motivo é o tipo de número que alguém dobra sem pensar.
const RETENCAO = [
    // Destinatário e ASSUNTO de cada carta que saiu. É metadado de
    // comunicação, o dado mais sensível desta lista -- daí o prazo mais curto.
    "sys_web_outbound_log" => 90,
    // O que um administrador fez, com o IP dele. É o registro do que foi feito
    // NAS contas das pessoas; curto demais e ele não serve para investigar
    // nada.
    "sys_admin_log" => 365,
    // Histórico de avisos. A pessoa já pode limpar quando quiser, então este
    // prazo só alcança o que ninguém limpou.
    "sys_notifications" => 365,
];

// O contador por aparelho é caso à parte e não entra na lista acima: ele NÃO é
// log. O valor do contador tem de persistir ou o anti-replay do device-auth
// para de funcionar -- apagar a linha seria quebrar a autenticação para
// economizar retenção. O que envelhece ali é o endereço IP, então a linha fica
// e só o IP sai.
const RETENCAO_IP_APARELHO = 30;

$db = DBUtil::getInstance()->getDB();
$total = 0;

foreach (RETENCAO as $tabela => $dias) {
    // Nome de tabela não vem de fora -- é constante deste arquivo --, mas a
    // interpolação fica explícita para quem for acrescentar uma linha aqui
    // saber que não há placeholder possível para identificador.
    $sql = "delete from `$tabela` where created_at < date_sub(now(), interval ? day)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        fwrite(STDERR, "purge_retention: nao consegui preparar para $tabela: " . $db->error . "\n");
        continue;
    }
    $stmt->bind_param("i", $dias);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    $total += max(0, $n);
    echo "$tabela: $n linha(s) além de $dias dias\n";
}

// O IP, não a linha.
$stmt = $db->prepare(
    "update sys_device_counter
        set last_ip = null
      where last_ip is not null
        and last_seen_at is not null
        and last_seen_at < date_sub(now(), interval ? day)");
if ($stmt) {
    $d = RETENCAO_IP_APARELHO;
    $stmt->bind_param("i", $d);
    $stmt->execute();
    echo "sys_device_counter: " . $stmt->affected_rows . " IP(s) limpo(s) além de $d dias (linhas preservadas)\n";
    $stmt->close();
} else {
    fwrite(STDERR, "purge_retention: nao consegui preparar a limpeza de IP: " . $db->error . "\n");
}

// As gravações do modo torneio. São ARQUIVO e não linha de banco, e a janela
// vem do fluxo pretendido: baixar logo depois da partida para converter em
// replay. O prazo mora no CaptureStoreUtil, junto da definição de onde os
// arquivos estão.
//
// A finalidade declarada pelo dono é dado de batalha -- trainer ID, nome do
// treinador, comandos, time. Finalidade declarada sem prazo é a mesma lacuna
// que o resto deste arquivo existe para fechar, então ela tem prazo.
require_once(__DIR__ . "/../classes/CaptureStoreUtil.php");
$loja = CaptureStoreUtil::getInstance();
if ($loja->readable()) {
    $n = $loja->purgeOld();
    echo "gravações do torneio: $n arquivo(s) além de " . CaptureStoreUtil::RETENTION_DAYS . " dias\n";
    $total += $n;
} else {
    echo "gravações do torneio: diretório ilegível, nada a fazer\n";
}

// Bloqueios de e-mail vencidos. Não é retenção de log: é a própria janela do
// bloqueio acabando, e o hash não tem razão de continuar existindo depois dela.
// Deixar para trás seria guardar o rastro de alguém que pediu exclusão por mais
// tempo do que o bloqueio precisava.
if ($db->query("show tables like 'sys_email_block'")->num_rows > 0) {
    $n = 0;
    if ($db->query("delete from sys_email_block where blocked_until <= now()")) {
        $n = $db->affected_rows;
    }
    echo "sys_email_block: $n bloqueio(s) vencido(s)\n";
    $total += max(0, $n);
}

echo "total removido: $total\n";
