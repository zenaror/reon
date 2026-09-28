<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Adds a per-account opt-in for Mobile Stadium content, mirroring
 * sys_users.custom_pokemon_news_opt_in exactly (same reasoning: every
 * Mobile Stadium distribution is fan-made/reconstructed, not official
 * Nintendo data, so serving it needs explicit consent the same way a
 * custom Pokémon News track does). Owner's request, 2026-09-28.
 *
 * Unlike bxt_news, there is no "is_custom" row-level flag to add: every
 * bxt_stadium_distributions row IS custom (there is no official
 * counterpart ever served), so the opt-in alone is enough to gate the
 * whole feature per account.
 */
final class AddMobileStadiumCustomOptIn extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('sys_users')) {
            $table = $this->table('sys_users');

            if (!$table->hasColumn('custom_mobile_stadium_opt_in')) {
                $opts = [
                    'null'    => false,
                    'default' => 0,
                ];

                if ($table->hasColumn('custom_pokemon_news_opt_in')) {
                    $opts['after'] = 'custom_pokemon_news_opt_in';
                } elseif ($table->hasColumn('trade_region_allowlist')) {
                    $opts['after'] = 'trade_region_allowlist';
                }

                $table->addColumn('custom_mobile_stadium_opt_in', 'boolean', $opts);
                $table->save();
            }
        }
    }
}
