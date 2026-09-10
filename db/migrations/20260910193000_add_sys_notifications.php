<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSysNotifications extends AbstractMigration
{
    public function change(): void
    {
        // One row per notification per user. There is no shared "broadcast"
        // row read by everyone: an admin announcement fans out into a row
        // each, so read state, ordering and the history page are the same
        // code whether a notification came from a cron, from a game, or from
        // a person. `batch` ties one fan-out back together for the admin
        // side.
        //
        // Two ways to carry the text, and both are needed:
        //   message_key + params  automatic notifications. The site speaks
        //                         seven languages and the cron that writes
        //                         the row speaks none of them, so the words
        //                         are chosen at display time, not at write
        //                         time.
        //   title + body          what a person typed. Translating that is
        //                         not ours to do, so it is stored verbatim.
        // `body` is also allowed alongside message_key for a detail line
        // that is data rather than prose ("MAGIKARP -> GROWLITHE").
        //
        // Deliberately no delete path for the owner of the row: the history
        // is the point. Read state is the only thing a reader changes.
        $table = $this->table('sys_notifications', ['id' => false, 'primary_key' => 'id']);
        $table->addColumn('id', 'integer', ['identity' => true, 'signed' => false, 'limit' => 11])
              ->addColumn('user_id', 'integer', ['signed' => false, 'limit' => 11, 'null' => false])
              ->addColumn('category', 'string', ['limit' => 24, 'null' => false, 'default' => 'system'])
              ->addColumn('game', 'string', ['limit' => 40, 'null' => true, 'default' => null])
              ->addColumn('message_key', 'string', ['limit' => 64, 'null' => true, 'default' => null])
              ->addColumn('params', 'text', ['null' => true, 'default' => null])
              ->addColumn('title', 'string', ['limit' => 160, 'null' => true, 'default' => null])
              ->addColumn('body', 'text', ['null' => true, 'default' => null])
              ->addColumn('link', 'string', ['limit' => 255, 'null' => true, 'default' => null])
              ->addColumn('batch', 'string', ['limit' => 40, 'null' => true, 'default' => null])
              ->addColumn('created_by', 'integer', ['signed' => false, 'limit' => 11, 'null' => true, 'default' => null])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('read_at', 'datetime', ['null' => true, 'default' => null])
              ->addIndex(['user_id', 'read_at'], ['name' => 'idx_user_unread'])
              ->addIndex(['user_id', 'id'], ['name' => 'idx_user_recent'])
              ->addIndex(['batch'], ['name' => 'idx_batch'])
              ->create();
    }
}
