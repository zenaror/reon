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

$fixturePath = __DIR__ . '/fixtures/bmvj/input_tester.flash';
$payload = file_get_contents($fixturePath);
if ($payload === false || strlen($payload) !== 168) {
    throw new RuntimeException('PAD TEST payload fixture is missing or has changed size');
}
$gameId = substr($payload, 9, 4);
$title = explode("\0", substr($payload, 0x0F), 2)[0];
$description = explode("\0", substr($payload, 0x24), 2)[0];
expectSame('G001', $gameId, 'payload game ID');
expectSame('PAD TEST', $title, 'payload game title');
expectSame(
    'e1f4e3c297448b4308eb45efc8107522ec0716d2961c5b1bdc5a9d22bc56881b',
    hash('sha256', $payload),
    'payload fixture checksum'
);

$officialRecord = makeBaselineRecord('G900');
$baseline = "\x01\x18\x00" . str_repeat("\0", 21) . $officialRecord;
$custom = [
    'game_id' => $gameId,
    'blocks_needed' => ord($payload[5]),
    'category_icon' => 0,
    'min_level_react' => 0,
    'min_level_smart' => 0,
    'min_level_sense' => 0,
    'min_hidden_level_a' => 0,
    'min_hidden_level_b' => 0,
    'title' => $title,
    'description' => $description,
    'download_filename' => '0000.' . $gameId . '.cgb',
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
expectSame($gameId, substr($record, 2, 4), 'custom game ID');
expectSame([0, 0], array_values(unpack('v2', substr($record, 12, 4))), 'hidden level fields');
$titleLength = ord($record[16]);
$cursor = 17 + $titleLength;
expectSame($title, substr($record, 17, $titleLength), 'title bytes');
$descriptionLength = ord($record[$cursor++]);
expectSame($description, substr($record, $cursor, $descriptionLength), 'description bytes');
$cursor += $descriptionLength;
$filenameLength = ord($record[$cursor++]);
expectSame('0000.' . $gameId . '.cgb', substr($record, $cursor, $filenameLength), 'server filename');
expectSame("\0\x01", substr($record, $cursor + $filenameLength, 2), 'filename terminator and type');

$withoutCustom = BmvjUtil::appendToCatalog($baseline, []);
if ($withoutCustom === null) throw new RuntimeException('baseline-only catalog was rejected');
expectSame(1, ord($withoutCustom[0]), 'opt-out catalog contains no custom entries');

$duplicate = $custom;
$duplicate['game_id'] = 'G900';
$duplicate['download_filename'] = '0000.G900.cgb';
$deduplicated = BmvjUtil::appendToCatalog($baseline, [$duplicate]);
if ($deduplicated === null) throw new RuntimeException('duplicate ID fixture catalog was rejected');
expectSame(1, ord($deduplicated[0]), 'custom ID cannot shadow a baseline ID');

echo "BMVJ catalog fixture checks passed\n";
