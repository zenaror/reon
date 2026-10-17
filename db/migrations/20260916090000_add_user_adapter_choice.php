<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUserAdapterChoice extends AbstractMigration
{
    public function change(): void
    {
        // Per-account adapter color and unmetered flag, used when bin_user_choice is enabled. NULL
        // means no personal choice: the account follows the global setting. Disabling user choice
        // preserves stored selections for later re-enabling. Values use libmobile
        // mobile_adapter_device: 8 blue, 9 yellow, 10 green, 11 red. The generated file adds the
        // 0x80 flag; it is not stored here.
        $this->table('sys_users')
             ->addColumn('adapter_device', 'integer', [
                 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                 'null' => true,
                 'default' => null,
                 'comment' => 'mobile_adapter_device chosen by the account; null means follow the panel',
             ])
             ->addColumn('adapter_unmetered', 'boolean', [
                 'null' => true,
                 'default' => null,
                 'comment' => 'unmetered flag chosen by the account; null means follow the panel',
             ])
             ->update();
    }
}
