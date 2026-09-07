<?php
// SPDX-License-Identifier: MIT
// Clears the mail trash once messages have sat in it past the retention
// window. Deleting from the Mobile Trainer or the webmail only sets
// deleted_at; this is the only thing in the system that removes mail for
// good, so the retention window is the whole safety net.
//
// Run from reon-mail-trash-purge.timer (daily).

require_once(__DIR__ . "/../classes/DBUtil.php");
require_once(__DIR__ . "/../classes/MailUtil.php");

$days = MailUtil::TRASH_RETENTION_DAYS;
$db = DBUtil::getInstance()->getDB();

// Counted first so the log line says what went, rather than just that the
// job ran -- this is a destructive job and its output is the only record.
$stmt = $db->prepare("
    select count(*) as c from sys_inbox
    where deleted_at is not null and deleted_at < date_sub(now(), interval ? day)
");
$stmt->bind_param("i", $days);
$stmt->execute();
$due = (int)$stmt->get_result()->fetch_assoc()["c"];

if ($due === 0) {
    echo date("c") . " nada a expurgar (retencao: {$days} dias)\n";
    exit(0);
}

$stmt = $db->prepare("
    delete from sys_inbox
    where deleted_at is not null and deleted_at < date_sub(now(), interval ? day)
");
$stmt->bind_param("i", $days);
$stmt->execute();

echo date("c") . " expurgadas {$stmt->affected_rows} mensagens da lixeira (retencao: {$days} dias)\n";
