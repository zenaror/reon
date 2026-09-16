<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DeviceAuthCounterPerDevice extends AbstractMigration
{
    public function up(): void
    {
        // The anti-replay counter lived on the account row, so two devices
        // sharing one config.bin (the owner's PC and 3DS) fought over one
        // counter: whichever ran last advanced it, and the other was refused
        // as stale until its next batch of 50 happened to overtake. The key
        // stays per account (the bin is meant to be copied between devices);
        // the counter and the authorization window move to one row per
        // (account, device), where the device id is chosen by the device
        // itself on first use and persisted alongside its counter.
        $this->table('sys_device_counter', ['id' => false, 'primary_key' => 'id'])
             ->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'limit' => 11])
             ->addColumn('user_id', 'integer', ['signed' => false, 'limit' => 11, 'null' => false])
             // 16 lowercase hex characters (8 random bytes). The empty string
             // is the pre-existing "legacy" device: requests without a device
             // parameter, from implementations that have not migrated yet.
             ->addColumn('device_id', 'string', ['limit' => 16, 'null' => false, 'default' => ''])
             ->addColumn('counter', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0])
             ->addColumn('authorized', 'boolean', ['null' => false, 'default' => false])
             ->addColumn('authorized_until', 'datetime', ['null' => true])
             ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
             ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
             ->addIndex(['user_id', 'device_id'], ['unique' => true, 'name' => 'idx_user_device'])
             ->create();

        // Every existing account keeps its counter as the legacy device, so a
        // client that still sends the old request form continues exactly
        // where it was.
        $this->execute(
            "insert into sys_device_counter (user_id, device_id, counter, authorized, authorized_until) " .
            "select user_id, '', counter, authorized, authorized_until from sys_device_authorization"
        );

        $this->table('sys_device_authorization')
             ->removeColumn('counter')
             ->removeColumn('authorized')
             ->removeColumn('authorized_until')
             ->update();
    }

    public function down(): void
    {
        $this->table('sys_device_authorization')
             ->addColumn('counter', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0, 'after' => 'device_auth_key'])
             ->addColumn('authorized', 'boolean', ['null' => false, 'default' => false, 'after' => 'counter'])
             ->addColumn('authorized_until', 'datetime', ['null' => true, 'after' => 'authorized'])
             ->update();

        // Only the legacy device's state fits back into the account row.
        $this->execute(
            "update sys_device_authorization a " .
            "join sys_device_counter c on c.user_id = a.user_id and c.device_id = '' " .
            "set a.counter = c.counter, a.authorized = c.authorized, a.authorized_until = c.authorized_until"
        );

        $this->table('sys_device_counter')->drop()->save();
    }
}
