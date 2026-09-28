<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Per-account opt-in for custom Game Boy Wars 3 content (maps made in the
 * REON map creator or uploaded by the team, ids 2000-9999), mirroring
 * sys_users.custom_pokemon_news_opt_in and custom_mobile_stadium_opt_in.
 * Owner's request, 2026-09-28. Off by default: an account that never
 * touches the setting sees only the official maps (ids 0000-1999).
 */
final class AddGbwarsCustomOptIn extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('sys_users')) {
            $table = $this->table('sys_users');

            if (!$table->hasColumn('custom_gbwars_opt_in')) {
                $opts = [
                    'null'    => false,
                    'default' => 0,
                ];

                if ($table->hasColumn('custom_mobile_stadium_opt_in')) {
                    $opts['after'] = 'custom_mobile_stadium_opt_in';
                }

                $table->addColumn('custom_gbwars_opt_in', 'boolean', $opts);
                $table->save();
            }
        }
    }
}
