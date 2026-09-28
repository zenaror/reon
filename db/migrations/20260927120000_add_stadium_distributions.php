<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStadiumDistributions extends AbstractMigration
{
    public function change(): void
    {
        // Mobile Stadium distributions, which Pokémon Stadium 2 reads from a
        // block Crystal downloaded.
        //
        // Born because the owner asked (2026-09-27) for the Stadium to be
        // assembled from the database, the way the Pokémon News already
        // are: there the binary lives in bxt_news.news_binary and a
        // two-line .php serves it. Same design here.
        //
        // What existed before were two static files inherited from upstream
        // in 2023, and one of them was worse than useless: the game
        // accepted it, charged the player 20, and overwrote the block they
        // already had, and the Stadium listed nothing. Taken offline on
        // 2026-09-27.
        //
        // The format's specification is in docs/mobile-stadium/spec.md,
        // read out of the disassembly by the PKHeX session. Every offset
        // cited here comes from there.
        $this->table('bxt_stadium_distributions')
             // A single letter, the way app/auto-schedule/files/bxt/<letter>/
             // uses it for the news: j, e, p, u, d, f, i, s. The path code
             // (CGB-BXTJ) is derived from it, so there is no second source
             // of truth.
             ->addColumn('game_region', 'char', ['limit' => 1, 'null' => false])

             // The 16 bytes the game compares. The payload carries the same
             // ones at 0xFEA, and the menu entry repeats them -- that is how
             // the game knows whether it already has that block. NEVER
             // reuse a value: whoever already has that File ID never
             // receives the new block.
             ->addColumn('file_id', 'binary', ['limit' => 16, 'null' => false])

             // The 6 schedule bytes: first day, last day, start hour, start
             // minute, end hour, end minute. FF = any, and FF x6 = always.
             //
             // Default FF x6 on purpose: the specification only traced that
             // case end to end. A custom window is possible and was not
             // exercised, so whoever uses one takes on that risk knowingly.
             ->addColumn('schedule', 'binary', ['limit' => 6, 'null' => false,
                 'default' => "\xFF\xFF\xFF\xFF\xFF\xFF"])

             // The cost, which the GAME reads from the served file's name --
             // not from here. This column is the intent; the server is what
             // composes the name, so the two can never disagree.
             //
             //   null = no digit in the name: free and NO LOGIN AT ALL
             //      0 = "0.": requires login, charges nothing   <- recommended
             //      N = "N.": charges N
             //
             // Recommended 0 because the payload carries a trainer name and
             // team: not something to serve without an authenticated
             // session. 4 or more digits in the name give error D3 in the
             // game, before the download.
             ->addColumn('cost', 'integer', ['null' => true, 'default' => 0])

             // Part of the served name, without the cost prefix and without
             // an extension. [a-z0-9-] only: it goes into a URL with a size
             // limit (<= 0xA5).
             ->addColumn('slug', 'string', ['limit' => 40, 'null' => false])

             // The block, exactly 0xFFE = 4094 bytes.
             //
             // varbinary, not blob: the limit is part of the contract -- the
             // game requires that exact size and refuses anything else with
             // error D3. Letting the type enforce the ceiling is a free
             // check.
             ->addColumn('payload', 'varbinary', ['limit' => 4094, 'null' => false])

             ->addColumn('active', 'boolean', ['null' => false, 'default' => false])

             // For the panel. Never served to the game.
             ->addColumn('title', 'string', ['limit' => 120, 'null' => true])

             // Which reading of the format produced this block. If the
             // specification is later corrected, this says which blocks
             // were made against the old version -- the difference between
             // re-checking everything and re-checking only what needs it.
             ->addColumn('spec_version', 'string', ['limit' => 60, 'null' => true])

             ->addColumn('created_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP', 'null' => false])

             // Unique per REGION, not globally: the seven western regions
             // share the same payload and therefore the same File ID, one
             // row each.
             ->addIndex(['game_region', 'file_id'], ['unique' => true])
             // The menu reads by region, and only the active ones.
             ->addIndex(['game_region', 'active'])
             ->create();
    }
}
