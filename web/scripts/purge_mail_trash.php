<?php
// SPDX-License-Identifier: MIT
//
// Purge mail Trash after its retention window via the daily reon-mail-trash-purge.timer.
// Game deletion marks messages in INBOX; web deletion moves them to Trash. First move
// game-marked messages to Trash, retaining the actor marker and starting their retention
// clock there; then expunge expired Trash. Using the original arrival date would
// immediately purge older mail just deleted by a player.

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

	// Step 1: move game-deleted messages from INBOX to Trash with their marker, starting
	// retention at that move.
	$marcadas = [];
	foreach (MailStoreUtil::rows($caixa, "INBOX") as $r) {
		if ($r["deleted_by"] === "game") $marcadas[] = $r["id"];
	}
	if (!empty($marcadas)) {
		$movidas += MailStoreUtil::moveToTrash($caixa, $marcadas);
	}

	// Step 2: permanently remove messages whose time in Trash exceeds retention.
	$expurgadas += MailStoreUtil::purgeOlderThan($caixa, $days);
}

echo date("c") . " {$contas} conta(s): {$movidas} movida(s) para a lixeira, "
	. "{$expurgadas} expurgada(s) (retencao: {$days} dias)\n";
