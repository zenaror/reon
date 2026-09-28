<?php
/**
 * Handler for Game Boy Wars 3 map menu
 * Route: 0.map_menu.txt
 *
 * Generates map pricing from database
 * Format: Multiple lines of "SSSS    EEEE    PPPP" where:
 *   SSSS = start map number (4 digits)
 *   EEEE = end map number (4 digits)
 *   PPPP = price in yen (4 digits)
 * Values separated by tabs (per Dan Docs: "whitespace may be tabs or spaces")
 */

require_once dirname(__DIR__, 2) . '/classes/DBUtil.php';
require_once dirname(__DIR__, 2) . '/classes/GameboyWars3Util.php';

// Who is asking: "0.map_menu.txt" carries a cost prefix, so download.php has
// already authenticated this request and left the account in the session.
// No account (should not happen) is treated as not opted in -- official maps
// only, never an error.
$userId = 0;
if (isset($_SESSION['userId']) && ($_SESSION['type'] ?? '') === 'cgb') {
    $userId = (int)$_SESSION['userId'];
}

// Custom maps (ids 2000-9999) are listed only to accounts that opted in
// (sys_users.custom_gbwars_opt_in). This gates the MENU, not map.php: the
// map file requests carry no cost prefix, so they are never authenticated
// and cannot tell who is asking -- and the game only requests numbers its
// own menu listed.
$maps = GameboyWars3Util::menuMaps(GameboyWars3Util::userOptedInCustom($userId));

// Build contiguous ranges with same price
$ranges = [];
$currentRange = null;

foreach ($maps as $row) {
    $mapNum = (int)$row['map_num'];
    $price = (int)$row['price_yen'];

    if ($currentRange === null) {
        // Start first range
        $currentRange = ['min' => $mapNum, 'max' => $mapNum, 'price' => $price];
    } elseif ($price === $currentRange['price'] && $mapNum === $currentRange['max'] + 1) {
        // Extend current range (same price AND contiguous)
        $currentRange['max'] = $mapNum;
    } else {
        // Different price or gap - save current range and start new one
        $ranges[] = $currentRange;
        $currentRange = ['min' => $mapNum, 'max' => $mapNum, 'price' => $price];
    }
}

// Don't forget the last range
if ($currentRange !== null) {
    $ranges[] = $currentRange;
}

header('Content-Type: text/plain');
foreach ($ranges as $range) {
    // Format: "SSSS\tEEEE\tPPPP" - tab-separated 4-digit values
    echo sprintf("%04d\t%04d\t%04d\n", $range['min'], $range['max'], $range['price']);
}
