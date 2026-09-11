<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBanAndAdminLog extends AbstractMigration
{
    public function change(): void
    {
        // Banning an account, as a timestamp rather than a boolean: "since
        // when" is part of the fact, and a null is a cleaner "not banned"
        // than a false that carries a stale reason beside it. Lifting a ban
        // clears all three fields together.
        //
        // The row is never deleted. An account that misbehaved is one whose
        // history matters most, and its device counters must survive anyway
        // -- removing a sys_device_counter row is what would let a captured
        // request for that device be replayed.
        $users = $this->table('sys_users');
        $users->addColumn('banned_at', 'datetime', ['null' => true, 'default' => null])
              ->addColumn('banned_reason', 'string', ['limit' => 190, 'null' => true, 'default' => null])
              ->addColumn('banned_by', 'integer', ['signed' => false, 'limit' => 11, 'null' => true, 'default' => null])
              ->addIndex(['banned_at'], ['name' => 'idx_banned_at'])
              ->update();

        // What an administrator did, and to whom. The panel can ban an
        // account, unblock a console, restart a service and write to every
        // player at once; a panel with those powers and no record of who
        // used them is a panel nobody can be held to.
        //
        // Append-only by intent: there is no update and no delete anywhere
        // in AdminUtil, the same rule the notification history follows.
        $log = $this->table('sys_admin_log', ['id' => false, 'primary_key' => 'id']);
        $log->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'limit' => 11])
            ->addColumn('admin_id', 'integer', ['signed' => false, 'limit' => 11, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 48, 'null' => false])
            ->addColumn('target', 'string', ['limit' => 190, 'null' => true, 'default' => null])
            ->addColumn('detail', 'text', ['null' => true, 'default' => null])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['admin_id', 'id'], ['name' => 'idx_admin_recent'])
            ->addIndex(['action'], ['name' => 'idx_action'])
            ->create();
    }
}
