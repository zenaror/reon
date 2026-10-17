<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSentThreadKey extends AbstractMigration
{
    public function change(): void
    {
        // Persist which conversation an outgoing message continues. Matching subject (without Re:)
        // and correspondent is sufficient for headerless incoming game mail, but incorrectly
        // groups a newly composed message with an older message sharing its subject. Reply intent
        // exists only when sending and must be stored. Existing rows remain NULL to retain their
        // previous inferred grouping; newly sent mail carries an explicit thread identity.
        $this->table('sys_sent')
             ->addColumn('thread_key', 'char', [
                 'limit' => 32,
                 'null' => true,
                 'after' => 'origin',
                 'comment' => 'conversation this message continues; null = infer from subject+party',
             ])
             ->addIndex(['user_id', 'thread_key'], ['name' => 'idx_user_thread'])
             ->update();
    }
}
