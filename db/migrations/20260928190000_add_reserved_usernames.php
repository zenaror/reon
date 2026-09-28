<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Usernames nobody may register, editable from the admin panel (Users ->
 * Reserved names). Seeded with the names that would pass for the service,
 * for staff or for another company, the role addresses mail systems treat as
 * special (RFC 2142), and words that would collide with a page of the site.
 * Every entry carries a comment so the list stays organised. Only new
 * sign-ups are checked: an account that already has one of these names
 * keeps it.
 */
final class AddReservedUsernames extends AbstractMigration
{
    private const SEED = [
        'Looks like staff or the service itself' => [
            'system', 'admin', 'administrator', 'root', 'superuser', 'sysadmin', 'staff',
            'moderator', 'mod', 'mods', 'support', 'helpdesk', 'help', 'official', 'team',
            'owner', 'operator', 'service', 'services', 'bot', 'bots',
        ],
        'The project and its games' => [
            'reon', 'reonteam', 'reonproject', 'mobilesystem', 'mobileadapter', 'mobiletrainer',
            'mobilestadium', 'gameboy', 'gameboywars', 'pokemon', 'pokemoncrystal', 'missingno',
        ],
        'Another company, or the network the adapter dials into' => [
            'nintendo', 'gamefreak', 'creatures', 'pokemoncompany', 'dion', 'kddi', 'docomo',
            'softbank', 'ntt',
        ],
        'Role address mail systems expect to be special (RFC 2142) or a server name' => [
            'postmaster', 'hostmaster', 'webmaster', 'abuse', 'security', 'noc', 'mailer',
            'mailerdaemon', 'daemon', 'noreply', 'donotreply', 'nobody', 'null', 'undefined',
            'mail', 'smtp', 'pop', 'pop3', 'imap', 'ftp', 'www', 'web', 'ssh', 'dns', 'api',
            'cgi', 'cdn', 'static', 'assets',
        ],
        'Contact, legal and data-protection addresses' => [
            'info', 'contact', 'sales', 'billing', 'press', 'media', 'news', 'notifications',
            'feedback', 'privacy', 'legal', 'dmca', 'copyright', 'compliance', 'gdpr', 'lgpd',
            'dpo', 'terms', 'tos', 'moderation',
        ],
        'Would collide with a page or route of the site' => [
            'login', 'logout', 'signup', 'register', 'account', 'accounts', 'user', 'users',
            'profile', 'settings', 'dashboard', 'download', 'downloads', 'guide', 'games', 'game',
            'trade', 'trades', 'rankings', 'ranking', 'home', 'index', 'about', 'faq', 'cgb',
        ],
        'Generic names that pass for "someone official" or nobody in particular' => [
            'anonymous', 'unknown', 'guest', 'everyone',
        ],
    ];

    public function change(): void
    {
        if (!$this->hasTable('sys_reserved_usernames')) {
            $this->table('sys_reserved_usernames', ['id' => false, 'primary_key' => 'username'])
                ->addColumn('username', 'string', ['limit' => 20, 'null' => false])
                ->addColumn('comment', 'string', ['limit' => 160, 'null' => true])
                ->addColumn('created_by', 'integer', ['signed' => false, 'null' => true, 'comment' => 'admin who added it; null for the seed'])
                ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
                ->create();
        }

        $rows = [];
        foreach (self::SEED as $comment => $names) {
            foreach ($names as $name) {
                $rows[$name] = ['username' => $name, 'comment' => $comment];
            }
        }
        // INSERT IGNORE through the table object is not available, so skip what
        // is already there: re-running the migration must never overwrite a
        // comment an admin has edited.
        $have = array_column($this->fetchAll('select username from sys_reserved_usernames'), 'username');
        $new = array_values(array_diff_key($rows, array_flip($have)));
        if ($new) {
            $this->table('sys_reserved_usernames')->insert($new)->saveData();
        }
    }
}
