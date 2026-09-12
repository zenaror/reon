<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddInboxReadAt extends AbstractMigration
{
    public function change(): void
    {
        // When this message was opened in the webmail. Null means unread.
        //
        // This replaces sys_users.mail_seen_id, which was a single
        // high-water mark for the whole mailbox: opening the list cleared
        // every message at once. Per-message state is what makes the count
        // fall one at a time, as reading actually happens.
        //
        // Web-side only, and it cannot become anything else. The Mobile
        // Trainer never reports reading -- it downloads and deletes -- so a
        // message the game took is gone from the inbox entirely rather than
        // marked read here.
        $this->table('sys_inbox')
             ->addColumn('read_at', 'timestamp', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'opened in the webmail; null means unread',
             ])
             // The unread count runs on every page for a signed-in visitor.
             ->addIndex(['recipient', 'read_at'], ['name' => 'idx_recipient_read'])
             ->update();

        $this->table('sys_users')
             ->removeColumn('mail_seen_id')
             ->update();
    }
}
