<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBirthDate extends AbstractMigration
{
    public function change(): void
    {
        // The person's date of birth, given by them at sign-up.
        //
        // Why it exists, given the server already received an age from the
        // cartridge: the in-game age is updated by hand by the person and
        // ages in place, and it arrives AFTER sign-up -- too late to govern
        // anything. A date solves both problems: it ages on its own and is
        // available before the first upload.
        //
        // Why a DATE and not just a yes/no "I am 13+", which was my
        // recommendation: the owner's decision on 2026-09-24, so the
        // rankings can be adapted to a per-country threshold in the future
        // (12 in Brazil, 13 under COPPA, 14 in Quebec, 13-16 under the
        // GDPR, 18 in India). A 13-only boolean cannot answer any of those
        // other questions later.
        //
        // NULL is allowed, and means "did not provide" -- the field is
        // OPTIONAL at sign-up, by his decision: registration stays free,
        // what the date governs is the rankings. Whoever does not provide
        // it stays under the old rule, the filter on the age the cartridge
        // sends.
        //
        // Never shown to anyone. And it is not the same data as
        // bxt_ranking.player_age: that one comes from the cartridge, this
        // one from the site, and confusing the two has already produced a
        // bug before, with the account's three identities.
        $this->table('sys_users')
             ->addColumn('birth_date', 'date', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'self-declared at sign-up, optional; gates ranking participation, never displayed',
             ])
             ->update();
    }
}
