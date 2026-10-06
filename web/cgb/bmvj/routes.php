<?php
// SPDX-License-Identifier: MIT

/**
 * Dispatch the two database-backed Net de Get resources.
 *
 * @param string $path RomList.cgb or the price-prefixed custom game filename
 * @param string|null $sessionId Normal download auth session, when present
 */
function handleBmvjRoute(string $path, ?string $sessionId = null): bool
{
    require_once dirname(__DIR__, 2) . '/classes/BmvjUtil.php';

    if ($path === 'RomList.cgb') {
        // RomList itself has no cost prefix. Use the same utility-auth flow
        // as custom Pokémon News so the catalog can safely be per-account.
        $userId = BmvjUtil::requireAuthenticatedUserId();
        $baseline = dirname(__DIR__) . '/download/A4/CGB-BMVJ/RomList.cgb';
        $bytes = @file_get_contents($baseline);
        if ($bytes === false) {
            http_response_code(503);
            return true;
        }

        $includeCustom = BmvjUtil::userOptedInCustom($userId);
        $remainingSlots = max(0, 78 - ord($bytes[0]));
        $customGames = BmvjUtil::activeCustomGames($remainingSlots, $includeCustom);
        $catalog = BmvjUtil::appendToCatalog($bytes, $customGames);
        if ($catalog === null) {
            http_response_code(503);
            return true;
        }

        header('Content-Type: application/octet-stream');
        echo $catalog;
        return true;
    }

    if (preg_match('/^[0-9]{1,4}\.G[0-9]{3}\.cgb$/', $path) !== 1) {
        return false;
    }

    // Authenticate before checking opt-in, and only charge after eligibility
    // is established. This prevents a guessed URL from charging an opted-out
    // account for a file it cannot receive.
    $userId = BmvjUtil::requireAuthenticatedUserId();
    $payload = BmvjUtil::customGamePayload($path, BmvjUtil::userOptedInCustom($userId));
    if ($payload === null) {
        http_response_code(404);
        return true;
    }

    $cost = getCost((string)($_GET['name'] ?? ''));
    if (!is_int($cost) || $cost < 0) {
        http_response_code(404);
        return true;
    }
    addCostToAccount($userId, $cost);

    header('Content-Type: application/octet-stream');
    echo $payload;
    return true;
}
