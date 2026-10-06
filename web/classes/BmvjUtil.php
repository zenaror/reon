<?php
// SPDX-License-Identifier: MIT

require_once dirname(__DIR__) . '/cgb/database.php';

/** REON persistence and binary catalog helpers for Net de Get. */
final class BmvjUtil
{
    public static function requireAuthenticatedUserId(): int
    {
        require_once dirname(__DIR__) . '/cgb/auth.php';
        $userId = function_exists('doAuth') ? (int)doAuth(2) : 0;
        if ($userId <= 0 && isset($_SESSION['userId'], $_SESSION['type']) && $_SESSION['type'] === 'cgb') {
            $userId = (int)$_SESSION['userId'];
        }
        if ($userId <= 0) {
            http_response_code(401);
            exit();
        }
        return $userId;
    }

    public static function userOptedInCustom(int $userId): bool
    {
        if ($userId <= 0) return false;
        $db = connectMySQL();
        $stmt = $db->prepare('select custom_bmvj_opt_in from sys_users where id = ? limit 1');
        if (!$stmt) return false;
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) return false;
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row !== null && (int)$row['custom_bmvj_opt_in'] === 1;
    }

    public static function activeCustomGames(int $limit = 78): array
    {
        $limit = max(0, min(78, $limit));
        if ($limit === 0) return [];
        $db = connectMySQL();
        $stmt = $db->prepare(
            'select game_id, blocks_needed, category_icon, min_level_react, min_level_smart, min_level_sense, '
            . 'min_hidden_level_a, min_hidden_level_b, title, description, download_filename, minigame_type, price_yen '
            . 'from bmvj_custom_games where is_active = 1 order by game_id limit ' . $limit
        );
        if (!$stmt || !$stmt->execute()) return [];
        $result = $stmt->get_result();
        $games = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $games;
    }

    public static function customGamePayload(string $filename): ?string
    {
        $db = connectMySQL();
        $stmt = $db->prepare(
            'select game_binary, price_yen from bmvj_custom_games where download_filename = ? and is_active = 1 limit 1'
        );
        if (!$stmt) return null;
        $stmt->bind_param('s', $filename);
        if (!$stmt->execute()) return null;
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null || (int)$row['price_yen'] !== (int)explode('.', $filename, 2)[0]) return null;
        return (string)$row['game_binary'];
    }

    /**
     * Preserve every baseline entry byte-for-byte, then append opted-in games.
     * RomList uses a one-byte count, little-endian offsets and variable records.
     */
    public static function appendToCatalog(string $baseline, array $customGames): ?string
    {
        if ($baseline === '' || strlen($baseline) < 3) return null;
        $count = ord($baseline[0]);
        if ($count < 1 || $count > 78 || strlen($baseline) < 1 + 2 * $count) return null;

        $records = [];
        for ($i = 0; $i < $count; $i++) {
            $offset = unpack('v', substr($baseline, 1 + 2 * $i, 2))[1];
            if ($offset < 1 + 2 * $count || $offset >= strlen($baseline)) return null;
            $records[$offset] = true;
        }
        $offsets = array_keys($records);
        sort($offsets, SORT_NUMERIC);
        if (count($offsets) !== $count) return null;

        $baselineRecords = [];
        $existingIds = [];
        foreach ($offsets as $i => $offset) {
            $end = $offsets[$i + 1] ?? strlen($baseline);
            if ($end <= $offset) return null;
            $record = substr($baseline, $offset, $end - $offset);
            $baselineRecords[] = $record;
            $existingIds[] = strlen($record) >= 6 ? substr($record, 2, 4) : '';
        }

        $customRecords = [];
        foreach ($customGames as $game) {
            $record = self::encodeCatalogRecord($game);
            if ($record === null || in_array(substr($record, 2, 4), $existingIds, true)) continue;
            $customRecords[] = $record;
        }
        if (count($baselineRecords) + count($customRecords) > 78) return null;

        $allRecords = array_merge($baselineRecords, $customRecords);
        $tableEnd = 1 + 2 * count($allRecords);
        // Keep the baseline header/padding bytes and expand only if the larger
        // offset table reaches past the existing first record.
        $out = substr($baseline, 0, $offsets[0]);
        if (strlen($out) < $tableEnd) {
            $out .= str_repeat("\0", $tableEnd - strlen($out));
        }
        $out[0] = chr(count($allRecords));

        foreach ($allRecords as $i => $record) {
            $offset = strlen($out);
            if ($offset > 0xFFFF || $offset + strlen($record) > 0x10000) return null;
            $out[1 + 2 * $i] = chr($offset & 0xFF);
            $out[2 + 2 * $i] = chr(($offset >> 8) & 0xFF);
            $out .= $record;
        }
        return $out;
    }

    private static function encodeCatalogRecord(array $game): ?string
    {
        $id = (string)($game['game_id'] ?? '');
        $filename = (string)($game['download_filename'] ?? '');
        $title = (string)($game['title'] ?? '');
        $description = (string)($game['description'] ?? '');
        if (preg_match('/^G[0-9]{3}$/', $id) !== 1
            || preg_match('/^[0-9]{4}\.G[0-9]{3}\.cgb$/', $filename) !== 1
            || substr($filename, strpos($filename, '.') + 1, 4) !== $id
            || (int)explode('.', $filename, 2)[0] !== (int)($game['price_yen'] ?? -1)
            || strlen($title) > 255 || strlen($description) > 255
            || strlen($title) === 0 || strlen($description) === 0) {
            return null;
        }

        $byte = static fn(string $key, int $max): ?string =>
            isset($game[$key]) && (int)$game[$key] >= 0 && (int)$game[$key] <= $max
                ? chr((int)$game[$key]) : null;
        $blocks = $byte('blocks_needed', 0x10);
        $category = $byte('category_icon', 0x08);
        $levelReact = $byte('min_level_react', 0xFF);
        $levelSmart = $byte('min_level_smart', 0xFF);
        $levelSense = $byte('min_level_sense', 0xFF);
        $type = $byte('minigame_type', 0xFF);
        if (in_array(null, [$blocks, $category, $levelReact, $levelSmart, $levelSense, $type], true)
            || !in_array(ord($type), [0x01, 0x02, 0x04, 0x08], true)) return null;

        $hiddenA = (int)($game['min_hidden_level_a'] ?? -1);
        $hiddenB = (int)($game['min_hidden_level_b'] ?? -1);
        if ($hiddenA < 0 || $hiddenA > 0xFFFF || $hiddenB < 0 || $hiddenB > 0xFFFF) return null;

        return $blocks . $category . $id . "\0\0"
            . $levelReact . $levelSmart . $levelSense . "\0"
            . pack('v2', $hiddenA, $hiddenB)
            . chr(strlen($title)) . $title
            . chr(strlen($description)) . $description
            . chr(strlen($filename)) . $filename . "\0" . $type;
    }
}
