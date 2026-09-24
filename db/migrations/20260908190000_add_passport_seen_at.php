<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPassportSeenAt extends AbstractMigration
{
    public function change(): void
    {
        // When this account dismissed the "your passport to REON" modal
        // that introduces config.bin. Null means not yet shown/dismissed.
        //
        // Defaulting to null is what makes this retroactive without a data
        // migration: every already-registered account gets null the moment
        // this column exists, so all of them see the modal on their next
        // page load, same as a brand new signup would.
        $this->table('sys_users')
             ->addColumn('passport_seen_at', 'timestamp', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'dismissed the config.bin passport modal; null means not yet shown',
             ])
             ->update();
    }
}
