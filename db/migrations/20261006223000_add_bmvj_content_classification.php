<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

final class AddBmvjContentClassification extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('bmvj_custom_games');
        if (!$table->hasColumn('is_custom')) {
            // Every previously imported entry was explicitly custom content.
            $table->addColumn('is_custom', 'boolean', ['null' => false, 'default' => 1])->save();
        }
    }
}
