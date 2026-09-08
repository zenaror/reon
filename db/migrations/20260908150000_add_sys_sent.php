<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSysSent extends AbstractMigration
{
    public function change(): void
    {
        // Copies of what an account has sent, from the webmail and from the
        // game alike.
        //
        // A separate table rather than a folder column on sys_inbox, and that
        // is the load-bearing decision: POP3 reads sys_inbox, so a sent row
        // living there would be offered to the Game Boy as if it had arrived.
        // Nothing can reach the game from here.
        $this->table('sys_sent')
             ->addColumn('user_id', 'integer', ['signed' => false])
             ->addColumn('recipient', 'string', ['limit' => 254])
             ->addColumn('origin', 'enum', [
                 'values' => ['web', 'game'],
                 'comment' => 'which client sent it',
             ])
             ->addColumn('message', 'blob', ['null' => true])
             ->addColumn('timestamp', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
             ->addIndex(['user_id', 'timestamp'], ['name' => 'idx_user_time'])
             ->create();
    }
}
