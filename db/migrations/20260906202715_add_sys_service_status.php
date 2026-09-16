<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSysServiceStatus extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // One row per service, upserted on every check -- this tracks the
        // latest known status only, not a history log.
        $table = $this->table('sys_service_status', ['id' => false, 'primary_key' => 'service']);
        $table->addColumn('service', 'string', ['limit' => 32, 'null' => false])
              ->addColumn('status', 'string', ['limit' => 16, 'null' => false, 'default' => 'unknown'])
              ->addColumn('detail', 'string', ['limit' => 255, 'null' => true])
              ->addColumn('checked_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->create();
    }
}
