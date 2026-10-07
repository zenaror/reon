<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

// Import a complete Maker HTTP body, never a raw flash image. Existing IDs
// are deliberately refused. Activation requires an explicit operator flag.
if (PHP_SAPI !== 'cli' || count($argv) < 2 || count($argv) > 3
    || (isset($argv[2]) && $argv[2] !== '--activate')) {
    fwrite(STDERR, "Usage: php maint/import_bmvj_game.php game.json [--activate]\n");
    exit(1);
}
define('CORE_PATH', dirname(__DIR__) . '/web/cgb');
require_once dirname(__DIR__) . '/web/classes/BmvjUtil.php';

try {
    $meta = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $filename = $meta['downloadFilename'] ?? '';
    if (!is_string($filename) || !preg_match('/^[0-9]{4}\.G[0-9]{3}\.cgb$/D', $filename)
        || !isset($meta['price']) || !is_int($meta['price']) || $meta['price'] < 0 || $meta['price'] > 9999
        || (int)substr($filename, 0, 4) !== $meta['price']) {
        throw new RuntimeException('Invalid historical price or filename metadata.');
    }
    $body = file_get_contents(dirname($argv[1]) . '/' . $filename);
    $expected = $meta['bodySha256'] ?? '';
    if (!is_string($body) || $body === '' || strlen($body) > 0xFFFFFF
        || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/D', $expected)
        || !hash_equals($expected, hash('sha256', $body))
        || ($meta['bodyBytes'] ?? null) !== strlen($body)) {
        throw new RuntimeException('Complete body length/hash mismatch.');
    }
    foreach (['titleHex', 'descriptionHex'] as $field) {
        if (!isset($meta[$field]) || !is_string($meta[$field])
            || !preg_match('/^(?:[a-fA-F0-9]{2})+$/D', $meta[$field])) {
            throw new RuntimeException('Missing or invalid encoded text.');
        }
    }
    $game = [
        'game_id' => $meta['gameId'] ?? '', 'blocks_needed' => $meta['blocks'] ?? null,
        'category_icon' => $meta['genre'] ?? null, 'minigame_type' => $meta['category'] ?? null,
        'min_level_react' => 0, 'min_level_smart' => 0, 'min_level_sense' => 0,
        'min_hidden_level_a' => 0, 'min_hidden_level_b' => 0,
        'title' => hex2bin($meta['titleHex']), 'description' => hex2bin($meta['descriptionHex']),
        'download_filename' => $filename, 'price_yen' => $meta['price'],
    ];
    $baseline = file_get_contents(CORE_PATH . '/download/A4/CGB-BMVJ/RomList.cgb');
    $catalog = BmvjUtil::appendToCatalog($baseline, [$game]);
    if ($catalog === null || ord($catalog[0]) !== ord($baseline[0]) + 1) {
        throw new RuntimeException('Invalid metadata, full catalog or baseline ID collision.');
    }
    $db = connectMySQL();
    $db->begin_transaction();
    $stmt = $db->prepare('INSERT INTO bmvj_custom_games '
        . '(game_id,blocks_needed,category_icon,minigame_type,title,description,download_filename,price_yen,game_binary,is_active) '
        . 'VALUES (?,?,?,?,?,?,?,?,?,?)');
    $active = isset($argv[2]) ? 1 : 0;
    $stmt->bind_param('siiisssisi', $game['game_id'], $game['blocks_needed'], $game['category_icon'],
        $game['minigame_type'], $game['title'], $game['description'], $filename, $game['price_yen'], $body, $active);
    $stmt->execute();
    $stmt = $db->prepare('SELECT game_binary FROM bmvj_custom_games WHERE game_id = ?');
    $stmt->bind_param('s', $game['game_id']);
    $stmt->execute();
    $stored = $stmt->get_result()->fetch_assoc();
    if ($stored === null || !hash_equals($expected, hash('sha256', $stored['game_binary']))) {
        throw new RuntimeException('Database body verification failed.');
    }
    $db->commit();
    echo 'Imported ' . $game['game_id'] . ' active=' . $active . ' bytes=' . strlen($body)
        . ' sha256=' . $expected . "\n";
} catch (Throwable $error) {
    if (isset($db)) $db->rollback();
    // Database exceptions can contain deployment details; do not print them.
    fwrite(STDERR, "Import refused; verify metadata/hash, schema and ID availability.\n");
    exit(1);
}
