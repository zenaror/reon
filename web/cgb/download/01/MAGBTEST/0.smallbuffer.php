<?php
// SPDX-License-Identifier: MIT
// MAGBTEST smallbuffer -- the complement to 0.bigbuffer.php, exclusive to the
// Mobile Adapter GB TestSuite ROM.
//
// Two things make this different from the big buffer, and both are the point:
//
// 1. Size. 128 bytes fits inside a single MAGB Transfer Data packet (254 bytes
//    max), so this exercises the no-streaming, no-reassembly regime -- the
//    opposite end from the big buffer's 8192-byte multi-packet path.
//
// 2. Auth type. This calls doAuth(2) itself, the way the Pokemon News scripts
//    do, instead of being authenticated by the download front-controller's
//    doAuth(1). Utility auth is otherwise reachable only through utility.php
//    and the news scripts, so without this fixture removing the NEWS ARTICLE
//    test would leave doAuth(2) with no coverage at all.
//
// It answers both GET and POST, deliberately. A POST here reuses the same
// Authorization inside the utility session's window rather than replaying a
// 401 challenge -- the shape real Pokemon News ranking queries use, and the
// reason auth.php caches utility_authed_user_id in the first place. That
// window is the one piece of server-side state in this path, so a test that
// never POSTs never touches it.

// Guarded: download.php defines this before including us, and redefining a
// constant is a warning today and an error in PHP 9.
if (!defined('CORE_PATH')) {
    define('CORE_PATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/cgb');
}
require_once(CORE_PATH . '/auth.php');

// Returns the userId; issues its own challenge and exits when unauthenticated.
$userId = doAuth(2);

$size = 128;

function magbtestChecksum($bytes) {
    $sum = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $sum = ($sum + ord($bytes[$i])) & 0xFFFF;
    }
    return $sum;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload leg: same contract as the big buffer's companion -- recompute the
    // sum over what arrived and report both the verdict byte and the value, so
    // a mismatch shows what the server actually received.
    $body = file_get_contents('php://input');
    $expected = sprintf('%04X', magbtestChecksum($body));

    $client = isset($_SERVER['HTTP_X_TEST_CHECKSUM'])
        ? strtoupper(trim($_SERVER['HTTP_X_TEST_CHECKSUM']))
        : null;

    header_remove();
    header('Content-Type: application/octet-stream');
    header('X-Test-Checksum: ' . $expected);
    // Echoed so the ROM can confirm utility auth resolved to a real account
    // rather than silently falling through.
    header('X-Test-User: ' . (int)$userId);
    echo ($client === $expected) ? "\x01" : "\x00";
    return;
}

$buffer = '';
for ($i = 0; $i < $size; $i++) {
    $buffer .= chr($i & 0xFF);
}

header_remove();
header('Content-Type: application/octet-stream');
header(sprintf('X-Test-Checksum: %04X', magbtestChecksum($buffer)));
header('X-Test-User: ' . (int)$userId);
echo $buffer;
