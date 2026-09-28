<?php
// SPDX-License-Identifier: MIT
// Applies the system records' retention windows.
//
// Exists because a window that is written down but not enforced is worse
// than no window at all: the privacy policy started promising these numbers
// on 2026-09-25, and a promise the system does not keep is a false
// statement, not an intention. If you change a number here, change it in
// the privacy page too -- they are the same numbers in two places, and
// nothing but this comment enforces that.
//
// What is deliberately NOT here:
//
//   nginx's log, which rotation already cuts at 14 days;
//   the systemd journal, which is configuration, not SQL -- it lives in
//     journald's MaxRetentionSec (30 days), out of PHP's reach;
//   the mail trash, which has its own script (purge_mail_trash.php),
//     because permanently deleting correspondence deserves a place of its
//     own.
//
// Runs via reon-retention-purge.timer (daily).

require_once(__DIR__ . "/../classes/DBUtil.php");

// The windows, in one place. The reason for each sits next to it, because
// "90" with no reason is the kind of number someone doubles without
// thinking.
const RETENTION = [
    // Recipient and SUBJECT of every letter sent. This is communications
    // metadata, the most sensitive item on this list -- hence the shortest
    // window.
    "sys_web_outbound_log" => 90,
    // What an administrator did, with their IP. This is the record of what
    // was done TO people's accounts; too short and it is useless for
    // investigating anything.
    "sys_admin_log" => 365,
    // Notification history. The person can already clear it whenever they
    // like, so this window only reaches what nobody cleared.
    "sys_notifications" => 365,
];

// The per-device counter is a case apart and does not go in the list above:
// it is NOT a log. The counter's value has to persist or device-auth's
// anti-replay check stops working -- deleting the row would break
// authentication to save on retention. What ages there is the IP address,
// so the row stays and only the IP goes.
const DEVICE_IP_RETENTION_DAYS = 30;

$db = DBUtil::getInstance()->getDB();
$total = 0;

foreach (RETENTION as $tabela => $dias) {
    // The table name does not come from outside -- it is a constant of
    // this file -- but the interpolation stays explicit so whoever adds a
    // line here knows there is no placeholder available for an identifier.
    $sql = "delete from `$tabela` where created_at < date_sub(now(), interval ? day)";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        fwrite(STDERR, "purge_retention: could not prepare for $tabela: " . $db->error . "\n");
        continue;
    }
    $stmt->bind_param("i", $dias);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    $total += max(0, $n);
    echo "$tabela: $n row(s) past $dias days\n";
}

// The IP, not the row.
$stmt = $db->prepare(
    "update sys_device_counter
        set last_ip = null
      where last_ip is not null
        and last_seen_at is not null
        and last_seen_at < date_sub(now(), interval ? day)");
if ($stmt) {
    $d = DEVICE_IP_RETENTION_DAYS;
    $stmt->bind_param("i", $d);
    $stmt->execute();
    echo "sys_device_counter: " . $stmt->affected_rows . " IP(s) cleared past $d days (rows preserved)\n";
    $stmt->close();
} else {
    fwrite(STDERR, "purge_retention: could not prepare the IP cleanup: " . $db->error . "\n");
}

// Tournament-mode recordings. These are FILES, not database rows, and the
// window comes from the intended flow: download right after the match to
// convert it into a replay. The period lives in CaptureStoreUtil, next to
// the definition of where the files are.
//
// The owner's stated purpose is battle data -- trainer ID, trainer name,
// commands, team. A stated purpose with no expiry is the same gap the rest
// of this file exists to close, so it gets one.
require_once(__DIR__ . "/../classes/CaptureStoreUtil.php");
$loja = CaptureStoreUtil::getInstance();
if ($loja->readable()) {
    $n = $loja->purgeOld();
    echo "tournament recordings: $n file(s) past " . CaptureStoreUtil::RETENTION_DAYS . " days\n";
    $total += $n;
} else {
    echo "tournament recordings: directory unreadable, nothing to do\n";
}

// Expired e-mail blocks. This is not log retention: it is the block's own
// window ending, and the hash has no reason to keep existing past it.
// Leaving it behind would mean keeping the trace of someone who asked to be
// erased for longer than the block needed.
if ($db->query("show tables like 'sys_email_block'")->num_rows > 0) {
    $n = 0;
    if ($db->query("delete from sys_email_block where blocked_until <= now()")) {
        $n = $db->affected_rows;
    }
    echo "sys_email_block: $n expired block(s)\n";
    $total += max(0, $n);
}

echo "total removed: $total\n";
