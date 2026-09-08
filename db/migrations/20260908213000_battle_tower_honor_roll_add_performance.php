<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class BattleTowerHonorRollAddPerformance extends AbstractMigration
{
    public function up(): void
    {
        // The honor roll kept only who led a room, not how well they did, so
        // the page could list leaders but not rank them. These mirror the
        // record columns the leader was promoted from; the trainer identity
        // lets the page collapse the same trainer leading on several days.
        $this->table('bxt_battle_tower_honor_roll')
             ->addColumn('trainer_id', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'game_region'])
             ->addColumn('secret_id', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'trainer_id'])
             ->addColumn('account_id', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'secret_id'])
             ->addColumn('num_trainers_defeated', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'level_decode'])
             ->addColumn('num_turns_required', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'num_trainers_defeated'])
             ->addColumn('damage_taken', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'num_turns_required'])
             ->addColumn('num_fainted_pokemon', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'after' => 'damage_taken'])
             ->update();

        // Backfill from the record each existing row was promoted from, while
        // that record still exists (records expire after 7 days). Rows whose
        // record is gone stay null and sort last.
        $this->execute(
            "update bxt_battle_tower_honor_roll h " .
            "join bxt_battle_tower_records r " .
            "  on r.game_region = h.game_region and r.level = h.level and r.room = h.room " .
            " and r.player_name = h.player_name and r.class = h.class " .
            " and r.pokemon1 = h.pokemon1 and r.pokemon2 = h.pokemon2 and r.pokemon3 = h.pokemon3 " .
            " and r.message_start = h.message_start " .
            "set h.trainer_id = r.trainer_id, h.secret_id = r.secret_id, h.account_id = r.account_id, " .
            "    h.num_trainers_defeated = r.num_trainers_defeated, h.num_turns_required = r.num_turns_required, " .
            "    h.damage_taken = r.damage_taken, h.num_fainted_pokemon = r.num_fainted_pokemon " .
            "where h.trainer_id is null"
        );
    }

    public function down(): void
    {
        $this->table('bxt_battle_tower_honor_roll')
             ->removeColumn('trainer_id')
             ->removeColumn('secret_id')
             ->removeColumn('account_id')
             ->removeColumn('num_trainers_defeated')
             ->removeColumn('num_turns_required')
             ->removeColumn('damage_taken')
             ->removeColumn('num_fainted_pokemon')
             ->update();
    }
}
