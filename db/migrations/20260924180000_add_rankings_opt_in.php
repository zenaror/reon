<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddRankingsOptIn extends AbstractMigration
{
    public function change(): void
    {
        // Whether what the game uploads shows up in the rankings.
        //
        // Until now there was no way out: the cartridge uploaded, the
        // server stored it, and the page published it -- no login to read
        // it, and nowhere to turn it off. This is data-protection register
        // finding 14, which is about the DEFAULT rather than the field: the
        // UK Children's Code asks that a privacy setting already start
        // closed in a service children access.
        //
        // Starts OFF, and that is what closes finding 14: appearing in the
        // rankings becomes something a person asks for, at sign-up or on
        // the account page, instead of something that happens to them.
        //
        // It could start this way without hiding anyone because bxt_ranking
        // was EMPTY on the day this column reached the server -- 13
        // accounts, zero ranking rows. Nobody who was already published
        // disappeared.
        //
        // NOT NULL with a default: null would mean "don't know", and every
        // read would have to decide what to do with that. The column always
        // answers, and the default lives here, in UserUtil and in
        // bxt_config -- all three the same.
        $this->table('sys_users')
             ->addColumn('rankings_opt_in', 'boolean', [
                 'null' => false,
                 'default' => false,
                 'comment' => 'whether what the game uploads may be counted and shown in the rankings',
             ])
             ->update();
    }
}
