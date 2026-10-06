<?php
// SPDX-License-Identifier: MIT
// Standalone offline test: php web/tests/test_bmvj_catalog.php

require_once dirname(__DIR__) . '/classes/BmvjUtil.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function makeBaselineRecord(string $id): string
{
    return "\0\0" . $id . "\0\0\0\0\0\0\0\0\0\0\0\0\0";
}

$officialRecord = makeBaselineRecord('G001');
$baseline = "\x01\x18\x00" . str_repeat("\0", 21) . $officialRecord;
$custom = [
    'game_id' => 'G999',
    'blocks_needed' => 1,
    'category_icon' => 2,
    'min_level_react' => 3,
    'min_level_smart' => 4,
    'min_level_sense' => 5,
    'min_hidden_level_a' => 0x1234,
    'min_hidden_level_b' => 0x5678,
    'title' => 'TITLE',
    'description' => 'DESCRIPTION',
    'download_filename' => '0000.G999.cgb',
    'minigame_type' => 1,
    'price_yen' => 0,
];

$catalog = BmvjUtil::appendToCatalog($baseline, [$custom]);
if ($catalog === null) throw new RuntimeException('valid fixture catalog was rejected');
expectSame(2, ord($catalog[0]), 'catalog entry count');
$offsetOfficial = unpack('v', substr($catalog, 1, 2))[1];
$offsetCustom = unpack('v', substr($catalog, 3, 2))[1];
expectSame($officialRecord, substr($catalog, $offsetOfficial, strlen($officialRecord)), 'official record preserved');

$record = substr($catalog, $offsetCustom);
expectSame('G999', substr($record, 2, 4), 'custom game ID');
expectSame([0x1234, 0x5678], array_values(unpack('v2', substr($record, 12, 4))), 'hidden level fields');
$titleLength = ord($record[16]);
$cursor = 17 + $titleLength;
expectSame('TITLE', substr($record, 17, $titleLength), 'title bytes');
$descriptionLength = ord($record[$cursor++]);
expectSame('DESCRIPTION', substr($record, $cursor, $descriptionLength), 'description bytes');
$cursor += $descriptionLength;
$filenameLength = ord($record[$cursor++]);
expectSame('0000.G999.cgb', substr($record, $cursor, $filenameLength), 'server filename');
expectSame("\0\x01", substr($record, $cursor + $filenameLength, 2), 'filename terminator and type');

$withoutCustom = BmvjUtil::appendToCatalog($baseline, []);
if ($withoutCustom === null) throw new RuntimeException('baseline-only catalog was rejected');
expectSame(1, ord($withoutCustom[0]), 'opt-out catalog contains no custom entries');

$duplicate = $custom;
$duplicate['game_id'] = 'G001';
$duplicate['download_filename'] = '0000.G001.cgb';
$deduplicated = BmvjUtil::appendToCatalog($baseline, [$duplicate]);
if ($deduplicated === null) throw new RuntimeException('duplicate ID fixture catalog was rejected');
expectSame(1, ord($deduplicated[0]), 'custom ID cannot shadow a baseline ID');

echo "BMVJ catalog fixture checks passed\n";
