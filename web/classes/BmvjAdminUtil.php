<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);
if (!defined('CORE_PATH')) define('CORE_PATH', dirname(__DIR__) . '/cgb');
require_once __DIR__ . '/BmvjUtil.php';
require_once __DIR__ . '/DBUtil.php';

final class BmvjAdminUtil
{
    public const MAX_BODY_BYTES = 2097152;
    public static function all(): array
    {
        return DBUtil::getInstance()->getDB()->query('SELECT game_id,blocks_needed,category_icon,minigame_type,price_yen,download_filename,is_custom,is_active,title,description,created_at,HEX(title) AS title_hex,HEX(description) AS description_hex,SHA2(game_binary,256) AS body_sha256,OCTET_LENGTH(game_binary) AS body_bytes FROM bmvj_custom_games ORDER BY game_id')->fetch_all(MYSQLI_ASSOC);
    }
    public static function find(string $id): ?array
    {
        $stmt = DBUtil::getInstance()->getDB()->prepare('SELECT * FROM bmvj_custom_games WHERE game_id=?');
        $stmt->bind_param('s', $id); $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    public static function form(array $row): array
    {
        $row['title_hex'] = strtoupper(bin2hex($row['title']));
        $row['description_hex'] = strtoupper(bin2hex($row['description']));
        $row['display_title'] = preg_match('/^[\x20-\x7e]+$/D', $row['title']) ? $row['title'] : $row['title_hex'];
        foreach (['title','description'] as $key) {
            $row[$key . '_text'] = preg_match('/^[\x20-\x7e]+$/D', $row[$key]) ? $row[$key] : '';
        }
        if (isset($row['game_binary'])) $row['body_sha256'] = hash('sha256', $row['game_binary']);
        unset($row['game_binary']);
        unset($row['title'], $row['description']);
        return $row;
    }
    public static function save(array $input, ?string $body, bool $editing): string
    {
        $id = (string)($input['game_id'] ?? '');
        if (!preg_match('/^G[0-9]{3}$/D', $id)) return 'invalid';
        $price = (string)($input['price_yen'] ?? '0');
        if (!preg_match('/^[0-9]{1,4}$/D', $price)) return 'invalid';
        $game = ['game_id' => $id, 'download_filename' => sprintf('%04d.%s.cgb', (int)$price, $id), 'price_yen' => (int)$price];
        foreach (['blocks_needed' => [1,16], 'category_icon' => [0,8], 'minigame_type' => [1,8],
            'min_level_react' => [0,255], 'min_level_smart' => [0,255], 'min_level_sense' => [0,255],
            'min_hidden_level_a' => [0,65535], 'min_hidden_level_b' => [0,65535]] as $key => [$min,$max]) {
            $value = (string)($input[$key] ?? '');
            if (!preg_match('/^[0-9]+$/D', $value) || (int)$value < $min || (int)$value > $max) return 'invalid';
            $game[$key] = (int)$value;
        }
        if (!in_array($game['minigame_type'], [1,2,4,8], true)) return 'invalid';
        foreach (['title','description'] as $key) {
            $hex = trim((string)($input[$key . '_hex'] ?? ''));
            if ($hex !== '') {
                if (!preg_match('/^(?:[a-fA-F0-9]{2}){1,255}$/D', $hex)) return 'invalid';
                $game[$key] = hex2bin($hex);
            } else {
                $text = (string)($input[$key . '_text'] ?? '');
                if (!preg_match('/^[\x20-\x7e]{1,255}$/D', $text)) return 'invalid';
                $game[$key] = $text;
            }
        }
        $game['is_custom'] = isset($input['is_custom']) ? 1 : 0;
        $game['is_active'] = isset($input['is_active']) ? 1 : 0;
        $db = DBUtil::getInstance()->getDB();
        try {
            $db->begin_transaction();
            // Serialize catalog capacity checks with other publication edits.
            $rows = $db->query('SELECT game_id,blocks_needed,category_icon,minigame_type,price_yen,download_filename,is_custom,is_active,title,description,min_level_react,min_level_smart,min_level_sense,min_hidden_level_a,min_hidden_level_b FROM bmvj_custom_games ORDER BY game_id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
            $old = null; $active = [];
            foreach ($rows as $row) {
                if ($row['game_id'] === $id) $old = $row;
                elseif ((int)$row['is_active'] === 1) $active[] = $row;
            }
            if (($editing && $old === null) || (!$editing && $old !== null)) { $db->rollback(); return 'exists'; }
            if ($body === null && $old !== null) $body = self::find($id)['game_binary'];
            if ($body === null || strlen($body) < 9 || ord($body[0]) === 0xC3 || strlen($body) > self::MAX_BODY_BYTES) { $db->rollback(); return 'upload-error'; }
            $hash = trim((string)($input['body_sha256'] ?? ''));
            if ($hash !== '' && (!preg_match('/^[a-fA-F0-9]{64}$/D', $hash) || !hash_equals(strtolower($hash), hash('sha256',$body)))) { $db->rollback(); return 'hash-error'; }
            $baseline = file_get_contents(CORE_PATH . '/download/A4/CGB-BMVJ/RomList.cgb');
            $single = BmvjUtil::appendToCatalog($baseline, [$game]);
            if ($single === null || ord($single[0]) !== ord($baseline[0]) + 1) { $db->rollback(); return 'invalid'; }
            if ($game['is_active']) $active[] = $game;
            $catalog = BmvjUtil::appendToCatalog($baseline, $active);
            if ($catalog === null || ord($catalog[0]) !== ord($baseline[0]) + count($active)) { $db->rollback(); return 'capacity'; }
            $game['game_binary'] = $body;
            $columns = array_keys($game); $values = array_values($game);
            if ($editing) {
                $sql = 'UPDATE bmvj_custom_games SET ' . implode(',', array_map(static fn($c) => $c . '=?', $columns)) . ' WHERE game_id=?';
                $values[] = $id;
            } else {
                $sql = 'INSERT INTO bmvj_custom_games (' . implode(',', $columns) . ') VALUES (' . implode(',',array_fill(0,count($columns),'?')) . ')';
            }
            $stmt = $db->prepare($sql); $stmt->bind_param(str_repeat('s',count($values)), ...$values); $stmt->execute();
            $db->commit(); return '';
        } catch (Throwable $error) {
            $db->rollback(); return 'database-error';
        }
    }
    public static function delete(string $id, string $confirmation): string
    {
        if (!preg_match('/^G[0-9]{3}$/D', $id) || $confirmation !== $id) return 'confirm-error';
        $stmt = DBUtil::getInstance()->getDB()->prepare('DELETE FROM bmvj_custom_games WHERE game_id=?');
        $stmt->bind_param('s',$id); $stmt->execute();
        return $stmt->affected_rows === 1 ? '' : 'exists';
    }
}
