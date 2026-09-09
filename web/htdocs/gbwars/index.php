<?php
/**
 * Game Boy Wars 3 - Map Gallery
 */

require_once dirname(__DIR__, 2) . '/classes/TemplateUtil.php';
require_once dirname(__DIR__, 2) . '/classes/DBUtil.php';
require_once dirname(__DIR__, 2) . '/classes/GameboyWars3Util.php';
require_once dirname(__DIR__, 2) . '/classes/PageUtil.php';

session_start();

$db = DBUtil::getInstance()->getDB();

// Get all active maps
$result = $db->query("SELECT map_id, map_name, width, height, price_yen FROM bww_maps WHERE is_active = 1 ORDER BY map_id");
$maps = $result->fetch_all(MYSQLI_ASSOC);

// Text sections live in web/pages/games/gbwars.<locale>.md.
echo TemplateUtil::render("gbwars/index", [
    'maps' => $maps,
    'doc_html' => PageUtil::html("games/gbwars"),
]);
