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
        $includeCustom = BmvjUtil::userOptedInCustom($userId);
        $games = BmvjUtil::activeCustomGames(78, $includeCustom);
        // The database is the single library. Historical fixtures are imported
        // as inactive drafts, so deletion cannot resurrect a static record.
        $catalog = BmvjUtil::appendToCatalog("\0", $games);
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

    // Authentication and opt-in gate content access. The numeric filename
    // prefix is historical game metadata; REON never bills or records a cost.
    $userId = BmvjUtil::requireAuthenticatedUserId();
    $payload = BmvjUtil::customGamePayload($path, BmvjUtil::userOptedInCustom($userId));
    if ($payload === null) {
        http_response_code(404);
        return true;
    }

    header('Content-Type: application/octet-stream');
    echo $payload;
    return true;
}
