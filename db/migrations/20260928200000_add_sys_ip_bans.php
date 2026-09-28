<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Who banned which address, and why. fail2ban only knows the address; the
 * reason an administrator gave in the admin panel (Bans) lives here, so the
 * list can always say why an address is banned. A row is closed, not deleted,
 * when the ban is lifted, so the history stays.
 */
final class AddSysIpBans extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('sys_ip_bans')) {
            return;
        }

        $this->table('sys_ip_bans', ['id' => true, 'signed' => false])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => false])
            ->addColumn('jail', 'string', ['limit' => 40, 'null' => false, 'default' => 'reon-manual'])
            ->addColumn('reason', 'string', ['limit' => 300, 'null' => false])
            ->addColumn('banned_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('banned_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('unbanned_by', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('unbanned_at', 'timestamp', ['null' => true, 'default' => null])
            ->addColumn('unban_note', 'string', ['limit' => 300, 'null' => true])
            ->addIndex(['ip'], ['name' => 'idx_ip_bans_ip'])
            ->create();
    }
}
