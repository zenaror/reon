<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddWebOutboundLog extends AbstractMigration
{
    public function change(): void
    {
        // Every message the webmail sends to a real internet address.
        //
        // Two jobs, and both matter. It is the rate limit's counter, which is
        // what keeps a single account from turning the site into a spam relay.
        // And it is the only record that a send happened at all: mail leaving
        // over the outbound path is not stored anywhere else, so without this
        // there would be no way to answer "what did this account send".
        //
        // The body is deliberately not kept -- the point is accountability for
        // the act, not a copy of everyone's correspondence.
        $this->table('sys_web_outbound_log')
             ->addColumn('user_id', 'integer', ['signed' => false])
             ->addColumn('recipient', 'string', ['limit' => 254])
             ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
             ->addColumn('accepted', 'boolean', ['default' => false])
             ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
             // The rate-limit query counts one user's recent rows, so it wants
             // both columns together.
             ->addIndex(['user_id', 'created_at'], ['name' => 'idx_user_created'])
             ->create();
    }
}
