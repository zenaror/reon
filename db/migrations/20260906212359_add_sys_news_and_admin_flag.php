<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSysNewsAndAdminFlag extends AbstractMigration
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
        // Site news posts, authored through /admin/news.php. Body is
        // Markdown, rendered to HTML at display time rather than stored
        // pre-rendered, so edits to the renderer apply to old posts too.
        $table = $this->table('sys_news', ['id' => false, 'primary_key' => 'id']);
        $table->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'limit' => 11])
              ->addColumn('slug', 'string', ['limit' => 160, 'null' => false])
              ->addColumn('title', 'string', ['limit' => 200, 'null' => false])
              ->addColumn('body', 'text', ['null' => false])
              ->addColumn('published_at', 'datetime', ['null' => true])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['slug'], ['unique' => true, 'name' => 'idx_slug'])
              ->addIndex(['published_at'], ['name' => 'idx_published_at'])
              ->create();

        // No role concept existed before this; the news panel is the first
        // thing that needs one.
        $users = $this->table('sys_users');
        $users->addColumn('is_admin', 'boolean', ['null' => false, 'default' => false, 'after' => 'timezone'])
              ->update();
    }
}
