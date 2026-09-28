<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStadiumReplays extends AbstractMigration
{
    public function change(): void
    {
        // Individually-uploaded replay records, meant to be picked (up to 3)
        // and combined into a bxt_stadium_distributions payload by
        // StadiumUtil::composePayload(), instead of requiring one
        // already-assembled 3-battle block per distribution.
        //
        // Born from the owner's request on 2026-09-28: export one replay at
        // a time from PKHeX, upload it, and choose which up-to-3 go into a
        // distribution from the admin panel, instead of re-recording a whole
        // 3-battle session every time one battle changes.
        //
        // "format", not "game_region": a replay record's byte layout depends
        // only on JP against western (record stride 0x480 against 0x490,
        // spec.md §3.2) -- a western replay is shared by all 7 western
        // codes, the same way a western bxt_stadium_distributions payload
        // is. Naming the column after a single region letter would suggest
        // a replay uploaded for 'e' cannot be used for 'p', which is false.
        $this->table('bxt_stadium_replays')
             ->addColumn('format', 'char', ['limit' => 1, 'null' => false])

             // The record as-is, INCLUDING its own trailer (marker + LE
             // sum16 over everything before it, spec.md §5.2) -- that
             // trailer is self-contained, computed only from bytes inside
             // this same record, so the record can be validated once here
             // and dropped into any of the 3 slots of a new payload
             // unchanged. Exactly 0x480 (JP) or 0x490 (western) bytes;
             // enforced by StadiumUtil before the INSERT, not by the column
             // (the two formats need different limits, and varbinary's
             // limit is a ceiling, not an equality check).
             ->addColumn('record', 'varbinary', ['limit' => 1168, 'null' => false])

             // For the admin panel's picker. Never sent to the game.
             ->addColumn('label', 'string', ['limit' => 80, 'null' => false])
             ->addColumn('source_note', 'string', ['limit' => 160, 'null' => true])

             ->addColumn('created_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP', 'null' => false])

             ->addIndex(['format'])
             ->create();
    }
}
