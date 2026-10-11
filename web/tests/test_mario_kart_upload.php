<?php
// SPDX-License-Identifier: MIT
// Run with PHP CLI. Synthetic data only; no database or configuration reads.
define('CORE_PATH', dirname(__DIR__).'/cgb');
require_once CORE_PATH.'/mario_kart.php';
function expectUpload($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
// Independent byte-wrapped reference of the AMKJ checksum routine 080646FC.
$checksums = [10141, 24924, 10155, 24926, 10153, 24928, 10151, 24930, 10149, 24916, 10163, 24918, 10161, 24920, 10159, 24922, 10157, 24908, 10171, 24910, 10169];
$data = [
    'player_id' => implode('', array_map('chr', range(0, 15))),
    'course' => 0, 'driver' => 7, 'name' => "!\"#$%", 'state' => 4,
    'unk18' => 0, 'time' => 11576,
    'input_data' => str_repeat(implode('', array_map('chr', range(0, 255))), 16),
    'full_name' => str_repeat('*', 16), 'phone_number' => str_repeat(chr(15), 12),
    'postal_code' => str_repeat(chr(15), 8), 'address' => str_repeat('*', 128),
];
$count = 0;
foreach ($checksums as $wire => $checksum) {
    $data['course'] = $wire;
    $data['unk18'] = $checksum;
    expectUpload(validateGhostUpload($data), 'Valid checksum refused: '.$wire);
    ++$count;
    $broken = $data;
    $broken['unk18'] ^= 1;
    expectUpload(!validateGhostUpload($broken), 'Invalid checksum accepted');
    ++$count;
    foreach (['player_id','name','input_data','full_name','phone_number','postal_code','address'] as $field) {
        $broken = $data;
        $broken[$field][0] = chr(ord($broken[$field][0]) ^ 1);
        expectUpload(!validateGhostUpload($broken), 'Corrupt field accepted: '.$field);
        ++$count;
    }
    foreach (['course','driver','state','time'] as $field) {
        $broken = $data;
        $broken[$field] ^= 1;
        expectUpload(!validateGhostUpload($broken), 'Corrupt numeric field accepted: '.$field);
        ++$count;
    }
}
for ($route = 0; $route < 20; ++$route) {
    for ($wire = 0; $wire < 256; ++$wire) {
        expectUpload(marioKartTimeTrialCourseMatches($route, $wire) === ($wire === $route || $wire === 20), 'Course policy mismatch');
        ++$count;
    }
}
expectUpload(!marioKartTimeTrialCourseMatches(-1, 20), 'Negative route accepted');
expectUpload(!marioKartTimeTrialCourseMatches(20, 20), 'Mobile GP treated as time trial');
echo 'PASS: '.($count+2)." synthetic checksum/course checks\n";
