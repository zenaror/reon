<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class TimezoneDefaultAsiaTokyo extends AbstractMigration
{
    public function up(): void
    {
        // sys_users.timezone held two kinds of value: the default "+0900" (a
        // fixed UTC offset) for every account that never touched the setting,
        // and an IANA identifier ("America/Sao_Paulo") for those that did.
        // Both parse, and Japan has no daylight saving so they render the
        // same, but one column should hold one kind of value: the identifier
        // is the one with rules, so the default becomes Asia/Tokyo.
        $this->execute("update sys_users set timezone = 'Asia/Tokyo' where timezone = '+0900'");
        $this->table('sys_users')
             ->changeColumn('timezone', 'string', ['limit' => 255, 'null' => false, 'default' => 'Asia/Tokyo'])
             ->update();
    }

    public function down(): void
    {
        $this->execute("update sys_users set timezone = '+0900' where timezone = 'Asia/Tokyo'");
        $this->table('sys_users')
             ->changeColumn('timezone', 'string', ['limit' => 255, 'null' => false, 'default' => '+0900'])
             ->update();
    }
}
