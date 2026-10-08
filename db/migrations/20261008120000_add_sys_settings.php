<?php

declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/** Bring the former one-off maintenance SQL into clean installations. */
final class AddSysSettings extends AbstractMigration
{
    public function up(): void
    {
        // Existing deployments keep their values and exact table definition.
        $this->execute("CREATE TABLE IF NOT EXISTS sys_settings (
            name varchar(64) NOT NULL,
            value varchar(255) NOT NULL,
            updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $this->execute("INSERT INTO sys_settings (name,value)
            VALUES ('pop3_password_fallback','1')
            ON DUPLICATE KEY UPDATE name=name");
    }

    public function down(): void
    {
        // This table predates the migration and holds operator choices.
        throw new RuntimeException('sys_settings contains persistent operator settings; restore a backup to roll back');
    }
}
