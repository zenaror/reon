<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class DeviceCounterLastQuery extends AbstractMigration
{
    public function up(): void
    {
        // The highest counter value a device has spent on a query. Kept
        // apart from `counter` (the last accepted authorize/deauthorize): the
        // query spends values ahead of it and must never be compared with
        // it. This one only decides whether a query may stamp "last seen":
        // a query whose value is not above it is a replay (or a device
        // catching up after losing its state) and leaves the stamp alone.
        $this->table('sys_device_counter')
             ->addColumn('last_query_counter', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0, 'after' => 'counter'])
             ->update();
    }

    public function down(): void
    {
        $this->table('sys_device_counter')
             ->removeColumn('last_query_counter')
             ->update();
    }
}
