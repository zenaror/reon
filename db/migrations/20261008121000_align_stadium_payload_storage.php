<?php

declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

/** MySQL's Phinx adapter maps large varbinary to BLOB; Oracle uses 4094. */
final class AlignStadiumPayloadStorage extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('bxt_stadium_distributions')) return;
        $row = $this->fetchRow('SELECT COUNT(*) AS oversized FROM bxt_stadium_distributions WHERE OCTET_LENGTH(payload)>4094');
        if ((int)$row['oversized'] !== 0) throw new RuntimeException('Stadium payload exceeds protocol limit; refusing to truncate');
        $this->execute('ALTER TABLE bxt_stadium_distributions MODIFY payload VARBINARY(4094) NOT NULL');
    }
    public function down(): void
    {
        $this->execute('ALTER TABLE bxt_stadium_distributions MODIFY payload BLOB NOT NULL');
    }
}
