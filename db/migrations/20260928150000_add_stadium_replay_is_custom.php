<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * bxt_stadium_replays.is_custom -- same official/custom distinction as
 * bxt_stadium_distributions.is_custom (20260928140000), one level down:
 * a replay record itself can be a faithful capture of a real historical
 * battle, or a fan-made/custom one. Owner's point, 2026-09-28: composing
 * an OFFICIAL distribution out of CUSTOM replays would misrepresent it,
 * so the distinction has to exist on the replay, not only on the
 * distribution it ends up in -- StadiumUtil::composePayload()'s caller
 * checks this before allowing an "official" compose.
 *
 * Default 0 (official) matches bxt_stadium_distributions' own default,
 * but unlike that table this is not a safe assumption to leave implicit:
 * the admin panel's upload form requires an explicit choice from here on.
 */
final class AddStadiumReplayIsCustom extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bxt_stadium_replays')) {
            $table = $this->table('bxt_stadium_replays');

            if (!$table->hasColumn('is_custom')) {
                $opts = [
                    'null'    => false,
                    'default' => 0,
                ];
                if ($table->hasColumn('format')) {
                    $opts['after'] = 'format';
                }
                $table->addColumn('is_custom', 'boolean', $opts);
                $table->save();
            }
        }
    }
}
