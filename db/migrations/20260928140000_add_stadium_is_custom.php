<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Mobile Stadium official/custom track, exactly the same shape as
 * bxt_news.is_custom (20260115150000_add_pokemon_news_custom_opt_in_and_bxt_news_is_custom.php).
 *
 * Correction to yesterday's opt-in migration (20260928130000): that one
 * made custom_mobile_stadium_opt_in a hard gate -- no opt-in meant no
 * Stadium menu at all. That is NOT how the Pokémon News opt-in works, and
 * it is not what the owner asked for (2026-09-28): a user who does not
 * opt in must still receive OFFICIAL content, exactly the same as an
 * account with custom_pokemon_news_opt_in off still receives official
 * Pokémon News, not nothing.
 *
 * bxt_stadium_distributions.is_custom
 *   - 0 = official: a faithful reconstruction of a real, historically
 *     distributed Nintendo block (what import_stadium_distribution.php
 *     has always produced).
 *   - 1 = custom: an admin-curated combination of replays that were never
 *     bundled together by Nintendo (what StadiumUtil::composePayload() /
 *     the admin panel's compose form produces). A combination that
 *     happens to reproduce a real historical block byte for byte is still
 *     custom by this definition -- the flag records HOW the row was made,
 *     not what it contains, the same as bxt_news.is_custom does.
 *
 * Existing rows default to 0 (official) -- correct for every row created
 * so far, since composePayload() did not exist until today.
 */
final class AddStadiumIsCustom extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bxt_stadium_distributions')) {
            $table = $this->table('bxt_stadium_distributions');

            $changed = false;

            if (!$table->hasColumn('is_custom')) {
                $opts = [
                    'null'    => false,
                    'default' => 0,
                ];
                if ($table->hasColumn('game_region')) {
                    $opts['after'] = 'game_region';
                }
                $table->addColumn('is_custom', 'boolean', $opts);
                $changed = true;
            }

            if (!$table->hasIndex(['game_region', 'is_custom', 'active'])) {
                $table->addIndex(['game_region', 'is_custom', 'active'], ['name' => 'idx_bxt_stadium_region_custom_active']);
                $changed = true;
            }

            if ($changed) {
                $table->save();
            }
        }
    }
}
