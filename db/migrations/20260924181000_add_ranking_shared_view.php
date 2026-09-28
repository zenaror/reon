<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRankingSharedView extends AbstractMigration
{
    public function up(): void
    {
        // What of the rankings may be SHOWN.
        //
        // The data keeps being written as always: the cartridge sends it,
        // the server stores it. What this view governs is publication --
        // the open page and the table the game shows other players.
        //
        // It is a view rather than a repeated `where` because the read
        // sites are NINE: eight in news.php, which builds what the game
        // sees, and one on the page. Spreading the same condition across
        // nine queries guarantees that someone will eventually add a tenth
        // and forget -- and the failure mode is silently publishing the
        // data of someone who asked not to be published. Here the rule
        // exists once.
        //
        // There are two conditions, and they are independent on purpose:
        //
        // 1) THE ACCOUNT'S PREFERENCE. Off by default (see the
        //    rankings_opt_in column): appearing is something the person
        //    asks for. The COALESCE covers a row with no matching account,
        //    treating the unknown as the default -- nobody chose, so it
        //    does not publish.
        //
        // 2) THE AGE DECLARED IN THE GAME, under 13, which does not publish
        //    regardless of the preference. COPPA triggers on actual
        //    knowledge, and storing an age field that reads 9 is actual
        //    knowledge; storing it and ignoring it would be the worst
        //    combination.
        //
        //    The number is weak in two known ways: it is self-declared
        //    inside the game, and it is UPDATED BY HAND by the person -- the
        //    game never touches it on its own, so an age typed once sits
        //    there ageing while the person grows up. That is why it is used
        //    only in the protective direction. Someone who lies upward
        //    gains no protection, but would not have had any anyway; a
        //    stale age that reads 12 when the person is already 15
        //    protects someone who did not need it, and that mistake is
        //    cheap and on the right side. What it never does is let
        //    someone IN.
        //
        //    A missing or zero age does not hide: it means "not provided",
        //    not "a child", and condition 1 already governs that case.
        //
        // WARNING: `r.*` is expanded at creation time. If bxt_ranking gains
        // a column, this view needs to be recreated, or the new column
        // will not appear to whoever reads through here.
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)"
        );
    }

    public function down(): void
    {
        $this->execute("DROP VIEW IF EXISTS bxt_ranking_shared");
    }
}
