<?php
// SPDX-License-Identifier: MIT
use Phinx\Migration\AbstractMigration;

final class SplitMonsterGuardiansSupport extends AbstractMigration
{
    public function up(): void
    {
        // Both IDs previously shared game_support_football. Copy only an
        // explicitly stored choice, and never overwrite a separate choice.
        $this->execute("INSERT IGNORE INTO sys_settings (name,value)
            SELECT 'game_support_monster_guardians',value FROM sys_settings
            WHERE name='game_support_football'");
    }
    public function down(): void
    {
        // Keep the separate setting as data; reverting code must not discard
        // the operator's preference for a later upgrade.
    }
}
