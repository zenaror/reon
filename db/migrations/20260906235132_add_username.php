<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUsername extends AbstractMigration
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
        // Splits what used to be one identity in two:
        //
        //   username          what the person picks and is known by (<= 20)
        //   dion_email_local  the 8-character form the adapter can hold
        //
        // dion_email_local stays the key every game-facing path uses (POP3
        // login, relay policy, Mario Kart), so it must remain unique on its
        // own; it's derived from the first 8 characters of the username, with
        // trailing digits substituted in when that prefix is already taken.
        $table = $this->table('sys_users');
        $table->addColumn('username', 'string', ['limit' => 20, 'null' => true, 'after' => 'email'])
              ->update();

        // Existing accounts were created before usernames existed: their
        // 8-character name is the only one they have, so it becomes both.
        $this->execute("update sys_users set username = dion_email_local where username is null");

        $this->table('sys_users')
             ->changeColumn('username', 'string', ['limit' => 20, 'null' => false])
             ->addIndex(['username'], ['unique' => true, 'name' => 'idx_username'])
             ->update();
    }
}
