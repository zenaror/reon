<?php
/**
 * Handler for Game Boy Wars 3 mercenary menu
 * Route: 0.youhei_menu.txt
 *
 * Generates mercenary unit pricing from database (bww_mercenary_prices,
 * added 2026-09-28 -- this used to be a hardcoded array here, with no
 * admin control at all).
 * Format: 5 lines, one price per mercenary unit (4-digit ASCII numbers)
 *
 * Units:
 * 0 = Infantry
 * 1 = AA Tank
 * 2 = Tank
 * 3 = Bomber
 * 4 = Frigate
 */

require_once dirname(__DIR__, 2) . '/classes/GameboyWars3Util.php';

// getMercenaryPrices() falls back to the same 5 values this file used to
// hardcode if the table is empty or the database is unreachable -- a
// broken read never breaks the menu, it just serves what always shipped.
$prices = GameboyWars3Util::getMercenaryPrices();

header('Content-Type: text/plain');
foreach ($prices as $price) {
    echo sprintf("%04d\n", $price);
}
