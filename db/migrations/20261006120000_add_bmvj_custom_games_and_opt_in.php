<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

/**
 * Store REON-made Net de Get minigames and let each account opt in to seeing
 * them in the BMVJ catalog. The preference is off by default; the catalog
 * must continue to include the game's official entries for every account.
 */
final class AddBmvjCustomGamesAndOptIn extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('sys_users')) {
            $users = $this->table('sys_users');
            if (!$users->hasColumn('custom_bmvj_opt_in')) {
                $opts = ['null' => false, 'default' => 0];
                if ($users->hasColumn('custom_gbwars_opt_in')) {
                    $opts['after'] = 'custom_gbwars_opt_in';
                }
                $users->addColumn('custom_bmvj_opt_in', 'boolean', $opts)->save();
            }
        }

        if (!$this->hasTable('bmvj_custom_games')) {
            $games = $this->table('bmvj_custom_games', ['id' => false, 'primary_key' => ['game_id']]);
            $games->addColumn('game_id', 'char', ['limit' => 4, 'null' => false, 'comment' => 'G followed by three decimal digits'])
                  ->addColumn('blocks_needed', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false])
                  ->addColumn('category_icon', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false])
                  ->addColumn('min_level_react', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false, 'default' => 0])
                  ->addColumn('min_level_smart', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false, 'default' => 0])
                  ->addColumn('min_level_sense', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false, 'default' => 0])
                  ->addColumn('min_hidden_level_a', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_SMALL, 'null' => false, 'default' => 0])
                  ->addColumn('min_hidden_level_b', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_SMALL, 'null' => false, 'default' => 0])
                  ->addColumn('title', 'blob', ['null' => false, 'comment' => 'Game-encoded title bytes without terminator'])
                  ->addColumn('description', 'blob', ['null' => false, 'comment' => 'Game-encoded description bytes without terminator'])
                  ->addColumn('download_filename', 'string', ['limit' => 64, 'null' => false])
                  ->addColumn('minigame_type', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_TINY, 'null' => false, 'default' => 1])
                  ->addColumn('price_yen', 'integer', ['signed' => false, 'limit' => MysqlAdapter::INT_SMALL, 'null' => false, 'default' => 0])
                  ->addColumn('game_binary', 'blob', ['limit' => MysqlAdapter::BLOB_MEDIUM, 'null' => false, 'comment' => 'Compressed BMVJ download payload'])
                  ->addColumn('is_active', 'boolean', ['signed' => false, 'null' => false, 'default' => false])
                  ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['is_active'], ['name' => 'idx_bmvj_custom_active'])
                  ->addIndex(['download_filename'], ['unique' => true, 'name' => 'uq_bmvj_custom_filename'])
                  ->create();
        }
    }
}
