<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The table AddGameboyWars3Tables' own docblock said this migration would
 * add ("bww_mercenary_prices: Mercenary unit pricing") but never did --
 * youhei_menu.php has served 5 prices hardcoded directly in the route
 * file ever since, with no database table and no admin control. Built
 * now for the admin panel (owner's request, 2026-09-28).
 *
 * Not region-specific: the route never branched on game region for this
 * endpoint, unlike bww_messages -- pricing is game balance, not
 * language, and changing that now would be a bigger behaviour change
 * than "give the 5 existing prices an admin screen".
 *
 * Seeded with the exact 5 values youhei_menu.php has always served
 * (GameboyWars3Util::MERCENARY_DEFAULT_PRICES), so migrating changes
 * nothing about what the game serves until an admin edits a price.
 */
final class AddBwwMercenaryPrices extends AbstractMigration
{
    public function change(): void
    {
        if (!$this->hasTable('bww_mercenary_prices')) {
            $table = $this->table('bww_mercenary_prices', ['id' => true, 'signed' => false]);
            $table->addColumn('unit_index', 'integer', [
                    'signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'null' => false,
                    'comment' => '0=Infantry, 1=AA Tank, 2=Tank, 3=Bomber, 4=Frigate'])
                  ->addColumn('price_yen', 'integer', [
                    'signed' => false, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => false])
                  ->addColumn('updated_at', 'timestamp', [
                    'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['unit_index'], ['unique' => true, 'name' => 'idx_unit_index'])
                  ->create();

            $defaults = [30, 50, 50, 50, 50]; // GameboyWars3Util::MERCENARY_DEFAULT_PRICES, copied so this
                                               // migration does not depend on that class ever changing.
            foreach ($defaults as $i => $price) {
                $this->execute("insert into bww_mercenary_prices (unit_index, price_yen) values ($i, $price)");
            }
        }
    }
}
