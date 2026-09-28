<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEmailBlock extends AbstractMigration
{
    public function change(): void
    {
        // Addresses that cannot sign up again for a while, after their
        // account has been deleted.
        //
        // Requested by the owner on 2026-09-25: to stop an account being
        // deleted and recreated in short order. The target is churn, not
        // punishment -- hence a window, not a permanent block.
        //
        // THIS IS WHERE A TENSION LIVES, and it is this table's whole
        // design: storing the address of someone who asked for deletion is
        // retaining personal data AFTER the deletion request, which is
        // exactly what the right to erasure exists to prevent. The way out
        // is to store a **hash**, not the address: it can answer "is this
        // address blocked?" at sign-up time, and it cannot be read back as
        // a list of who it was.
        //
        // The hash is peppered with a secret from config.json, outside the
        // database. Without a pepper, an e-mail hash is guessable by brute
        // force -- an address has little entropy, and lists of addresses
        // exist by the millions. And the pepper only helps if it lives
        // somewhere other than the table: a secret in the same dump as the
        // hashes protects nothing.
        //
        // This table does NOT go on AccountDataUtil's list, and that is
        // deliberate even though that list's own rule is "one list governs
        // both export and deletion". It is what SURVIVES deletion, on
        // purpose; there is no account left to tie it to, because the
        // account has stopped existing. There is nothing to export (a hash
        // is not information for the person) and nothing to delete
        // (deleting it would undo the block).
        $this->table('sys_email_block', ['id' => false, 'primary_key' => ['email_hash']])
             ->addColumn('email_hash', 'char', [
                 'limit' => 64,
                 'null' => false,
                 'comment' => 'sha256 of the lowercased address, peppered from config; never reversible',
             ])
             ->addColumn('blocked_until', 'datetime', ['null' => false])
             ->addColumn('created_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP',
                 'null' => false,
             ])
             // So the purge job can find expired rows without scanning the table.
             ->addIndex(['blocked_until'])
             ->create();
    }
}
