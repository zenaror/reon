<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UniqueDionEmailLocal extends AbstractMigration
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
        // dion_email_local is what POP3 authenticates against and what inbound
        // mail is routed by, so two accounts sharing one would mean logging in
        // as, and receiving the mail of, whichever row the query happened to
        // return first.
        //
        // Uniqueness was only ever enforced in PHP. That was survivable while
        // the value was typed in and checked on the way past, but it is now
        // derived automatically at signup, so two concurrent registrations
        // could derive the same prefix and both be written. The constraint
        // belongs in the database, where the race can't get around it.
        $this->table('sys_users')
             ->addIndex(['dion_email_local'], ['unique' => true, 'name' => 'idx_dion_email_local'])
             ->update();
    }
}
