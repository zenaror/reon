<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddReminderLog extends AbstractMigration
{
    public function change(): void
    {
        // When an automatic reminder was last sent.
        //
        // Exists because the notification bell does not serve as memory for
        // this. Reading one sets `read_at`, but DISMISSING deletes the row
        // -- and with the row goes the proof that we already warned them.
        // Without this record, clearing notifications would bring the
        // reminder back on the next delivery, which is exactly the
        // opposite of what dismissing is supposed to mean.
        //
        // One row per account and key, overwritten on every send. It is not
        // history: it is "the last time we told this person about this",
        // which is all the resend decision needs to know.
        //
        // Never shown to anyone. But it is data tied to an account, so it
        // goes on AccountDataUtil's list, which governs export and
        // deletion -- a table forgotten there is data that survives the
        // account's deletion.
        $this->table('sys_reminder_log', ['id' => false, 'primary_key' => ['user_id', 'message_key']])
             ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
             ->addColumn('message_key', 'string', ['limit' => 64, 'null' => false])
             ->addColumn('sent_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP',
                 'update' => 'CURRENT_TIMESTAMP',
                 'null' => false,
             ])
             ->addIndex(['sent_at'])
             ->create();
    }
}
