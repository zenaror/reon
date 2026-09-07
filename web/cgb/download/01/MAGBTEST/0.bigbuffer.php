<?php
// SPDX-License-Identifier: MIT
// MAGBTEST bigbuffer -- deterministic large-buffer download, exclusive to
// the Mobile Adapter GB TestSuite ROM. Not tied to any real game/save data;
// exists purely to stress-test client-side (libmobile) reassembly of a
// single large HTTP body across many MAGB Transfer Data packets (max 254
// bytes each per the MAGB framing). Size anchored to the real-world ceiling
// documented for Pokemon News (a single MBC3 SRAM bank, 8KB) rather than an
// arbitrary number.
//
// Checksum contract agreed with the TestSuite ROM: a 16-bit running sum
// (mod 65536, no seed) over every byte of the response BODY only -- no
// HTTP headers, no length field -- reported as 4 uppercase hex digits,
// zero-padded, in the X-Test-Checksum response header.

$size = 8192;

$buffer = '';
$checksum = 0;
for ($i = 0; $i < $size; $i++) {
    $byte = $i & 0xFF;
    $buffer .= chr($byte);
    $checksum = ($checksum + $byte) & 0xFFFF;
}

header('Content-Type: application/octet-stream');
header(sprintf('X-Test-Checksum: %04X', $checksum));
echo $buffer;
