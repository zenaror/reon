<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DeviceCounterNicknameBlock extends AbstractMigration
{
    public function up(): void
    {
        // The account page grows a "connected devices" list (in the spirit of
        // Google's): a nickname the owner gives a device once they have
        // recognised it, a per-device block that keeps the row and its
        // counter (deleting the row would let a captured request for that
        // device be replayed), and where/when the device was last seen so the
        // one that just connected is recognisable.
        $this->table('sys_device_counter')
             ->addColumn('nickname', 'string', ['limit' => 32, 'null' => true, 'default' => null, 'after' => 'device_id'])
             ->addColumn('blocked', 'boolean', ['null' => false, 'default' => false, 'after' => 'authorized_until'])
             ->addColumn('last_ip', 'string', ['limit' => 45, 'null' => true, 'default' => null, 'after' => 'blocked'])
             ->addColumn('last_seen_at', 'datetime', ['null' => true, 'default' => null, 'after' => 'last_ip'])
             ->update();
    }

    public function down(): void
    {
        $this->table('sys_device_counter')
             ->removeColumn('nickname')
             ->removeColumn('blocked')
             ->removeColumn('last_ip')
             ->removeColumn('last_seen_at')
             ->update();
    }
}
