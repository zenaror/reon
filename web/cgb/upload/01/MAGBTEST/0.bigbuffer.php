<?php
// SPDX-License-Identifier: MIT
// MAGBTEST bigbuffer upload -- companion to
// web/cgb/download/01/MAGBTEST/0.bigbuffer.php. The ROM sends the checksum
// it computed on its end (of whatever it's confirming -- typically the
// buffer it just downloaded) in the X-Test-Checksum request header; this
// recomputes the same 16-bit running sum over the POSTed body and reports
// whether the two agree, so both directions of the transfer get verified,
// not just the download side.

$body = file_get_contents('php://input');

$checksum = 0;
$len = strlen($body);
for ($i = 0; $i < $len; $i++) {
    $checksum = ($checksum + ord($body[$i])) & 0xFFFF;
}
$expected = sprintf('%04X', $checksum);

$clientChecksum = isset($_SERVER['HTTP_X_TEST_CHECKSUM'])
    ? strtoupper(trim($_SERVER['HTTP_X_TEST_CHECKSUM']))
    : null;
$match = ($clientChecksum === $expected);

header('Content-Type: application/octet-stream');
header('X-Test-Checksum: ' . $expected);
echo $match ? "\x01" : "\x00";
