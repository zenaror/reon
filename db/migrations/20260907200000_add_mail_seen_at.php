<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMailSeenAt extends AbstractMigration
{
    public function change(): void
    {
        // Highest sys_inbox id this account had seen when it last opened the
        // webmail inbox, used to tell "you have 5 messages" from "2 arrived
        // since you last looked".
        //
        // An id rather than a timestamp, for two reasons. sys_inbox.timestamp
        // is the message's own date, which can be older than its insertion --
        // backdated mail would never register as new. And a timestamp compare
        // has second granularity, so mail arriving in the same second as the
        // visit would be missed. Ids are monotonic and have neither problem.
        //
        // This is web-side state only, and deliberately not a read receipt:
        // the Mobile Trainer deletes what it touches and never reports
        // reading, so nothing here can reflect what the game did. Zero means
        // the account has never opened the webmail, so everything is new.
        $this->table('sys_users')
             ->addColumn('mail_seen_id', 'integer', [
                 'signed' => false,
                 'default' => 0,
                 'comment' => 'highest sys_inbox id seen in the webmail',
             ])
             ->update();
    }
}
