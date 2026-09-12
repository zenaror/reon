<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddInboxTrash extends AbstractMigration
{
    public function change(): void
    {
        // The Mobile Trainer has no "leave on server" mode: every one of its
        // three paths ends in a POP3 DELE, and one of them deletes without
        // ever downloading the message. Until now DELE removed the row, so a
        // mistouch destroyed mail that nothing had read.
        //
        // These columns turn that deletion into a trash with a retention
        // window. The POP3 maildrop selects filter on deleted_at, so the game
        // still sees its mailbox empty out exactly as before -- without the
        // filter it would re-download the trash on every sync.
        //
        // retrieved_at is set on RETR rather than inferred, because "the game
        // downloaded this" and "the game discarded this unread" are otherwise
        // indistinguishable once both have become a deleted row.
        $this->table('sys_inbox')
             ->addColumn('deleted_at', 'timestamp', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'moved to trash at; null means in the inbox',
             ])
             ->addColumn('deleted_by', 'enum', [
                 'values' => ['game', 'web'],
                 'null' => true,
                 'default' => null,
                 'comment' => 'which client trashed it',
             ])
             ->addColumn('retrieved_at', 'timestamp', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'first POP3 RETR; null means never downloaded',
             ])
             // The maildrop query is per-recipient and runs on every POP3
             // login, so it gets the composite; the purge job scans on
             // deleted_at alone.
             ->addIndex(['recipient', 'deleted_at'], ['name' => 'idx_recipient_deleted'])
             ->addIndex(['deleted_at'], ['name' => 'idx_deleted_at'])
             ->update();
    }
}
