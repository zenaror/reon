<?php
// SPDX-License-Identifier: MIT
// Temporary instrumentation for the Mobile Adapter GB TestSuite ROM, scoped
// strictly to the /01/MAGBTEST/ fixtures so no real game traffic is recorded.
//
// It exists because an authenticated upload is three requests, and the middle
// one never reaches a handler: doAuth() type 0 answers with Gb-Auth-ID and
// exit()s (see auth.php), discarding whatever body came with it. Logging from
// the front controller is therefore the only way to see whether the ROM put
// its payload on the wrong request.

define('MAGBTEST_LOG', '/var/log/reon/magbtest.log');

function magbtestIsTestPath() {
    return isset($_GET['name']) && strpos($_GET['name'], '/MAGBTEST/') !== false;
}

function magbtestLog($stage) {
    if (!magbtestIsTestPath()) return;

    // php://input is rewindable for the raw bodies the adapter sends (it is
    // not for multipart/form-data, which the adapter never produces), so
    // reading it here does not consume it for the handler downstream.
    $body = file_get_contents('php://input');
    $len = strlen($body);

    $checksum = 0;
    for ($i = 0; $i < $len; $i++) {
        $checksum = ($checksum + ord($body[$i])) & 0xFFFF;
    }

    // Content-Length is what the client claimed; $len is what actually
    // arrived. A mismatch is the signature of a truncated transfer.
    $lines = [
        sprintf('[%s] %s', date('Y-m-d H:i:s'), $stage),
        sprintf('  %s %s from %s',
            $_SERVER['REQUEST_METHOD'] ?? '?',
            $_SERVER['REQUEST_URI'] ?? '?',
            $_SERVER['REMOTE_ADDR'] ?? '?'),
        sprintf('  Content-Length declarado: %s | bytes recebidos: %d',
            $_SERVER['CONTENT_LENGTH'] ?? '(ausente)', $len),
        sprintf('  Authorization: %s | Gb-Auth-ID: %s | X-Test-Checksum: %s',
            isset($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '' ? 'presente' : '(ausente)',
            $_SERVER['HTTP_GB_AUTH_ID'] ?? '(ausente)',
            $_SERVER['HTTP_X_TEST_CHECKSUM'] ?? '(ausente)'),
    ];
    if ($len > 0) {
        $lines[] = sprintf('  checksum do corpo: %04X | inicio: %s | fim: %s',
            $checksum,
            bin2hex(substr($body, 0, 8)),
            bin2hex(substr($body, -8)));
    }

    @file_put_contents(MAGBTEST_LOG, implode("\n", $lines) . "\n", FILE_APPEND);
}
