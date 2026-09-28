<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RankingSharedBirthDate extends AbstractMigration
{
    public function up(): void
    {
        // Adds the account's date of birth to the publication filter.
        //
        // There was already an age cutoff here, but based on the age the
        // CARTRIDGE sends (r.player_age). It is weak for two known reasons:
        // it is updated by hand and ages in place, and it only exists after
        // the first upload. The account's date of birth is better on both
        // counts.
        //
        // Both cutoffs stay, and this is not redundancy: they are different
        // sources, and a person may have provided one and not the other.
        // Someone who gives the date on the site but never touched the
        // in-game age is covered by the first; someone who signed up before
        // the date field existed is covered by the second.
        //
        // NULL passes through, on purpose: it means "did not provide", not
        // "a child". Every account that existed when this column was born
        // is in that state, and blocking them would mean interrupting
        // people who were already using the service over a field that did
        // not exist when they signed up.
        //
        // CURDATE() in a view's WHERE clause is evaluated on every query, so
        // someone who turns 13 tomorrow becomes eligible tomorrow, with
        // nothing needing to run.
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)
                AND (u.birth_date IS NULL OR u.birth_date <= (CURDATE() - INTERVAL 13 YEAR))"
        );
    }

    public function down(): void
    {
        $this->execute(
            "CREATE OR REPLACE VIEW bxt_ranking_shared AS
             SELECT r.*
               FROM bxt_ranking r
               LEFT JOIN sys_users u ON u.id = r.account_id
              WHERE COALESCE(u.rankings_opt_in, 0) = 1
                AND (r.player_age IS NULL OR r.player_age = 0 OR r.player_age >= 13)"
        );
    }
}
