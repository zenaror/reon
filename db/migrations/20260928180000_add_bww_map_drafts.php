<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Drafts for the GB Wars map creator (admin/gbwars_map_editor.php).
 *
 * A draft is a map still being worked on, kept in the creator's own logical
 * form (tile grid + unit list + metadata) instead of the game's checksummed
 * file: the file can only be built from a complete map, and a half-painted
 * one is exactly what a draft is. Publishing builds the real file with
 * GameboyWars3Util::createMapData() and inserts it into bww_maps (inactive).
 */
final class AddBwwMapDrafts extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('bww_map_drafts')) {
            return;
        }

        $this->table('bww_map_drafts', ['id' => true, 'signed' => false])
            ->addColumn('title', 'string', ['limit' => 60, 'null' => false, 'comment' => 'Admin-only label for the draft'])
            ->addColumn('width', 'integer', ['signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'null' => false])
            ->addColumn('height', 'integer', ['signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'null' => false])
            ->addColumn('tiles', 'blob', ['null' => false, 'comment' => 'width*height terrain ids, row by row'])
            ->addColumn('units', 'blob', ['null' => false, 'comment' => '3 bytes per unit: x, y, unit id'])
            ->addColumn('map_name', 'string', ['limit' => 16, 'null' => false, 'default' => '', 'comment' => 'In-game name, max 8 game characters'])
            ->addColumn('category', 'string', ['limit' => 16, 'null' => false, 'default' => 'REON', 'comment' => 'In-game category line, max 9 game characters'])
            ->addColumn('player_gold', 'integer', ['signed' => false, 'null' => false, 'default' => 10000])
            ->addColumn('enemy_gold', 'integer', ['signed' => false, 'null' => false, 'default' => 10000])
            ->addColumn('player_materials', 'integer', ['signed' => false, 'null' => false, 'default' => 100])
            ->addColumn('enemy_materials', 'integer', ['signed' => false, 'null' => false, 'default' => 100])
            ->addColumn('price_yen', 'integer', ['signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => false, 'default' => 10])
            ->addColumn('name_e', 'string', ['limit' => 12, 'null' => true])
            ->addColumn('category_e', 'string', ['limit' => 9, 'null' => true])
            ->addColumn('published_map_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'bww_maps.id of the last publish'])
            ->addColumn('publish_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->create();
    }
}
