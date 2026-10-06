<?php
// SPDX-License-Identifier: MIT
// Isolated HTTP harness: php -S 127.0.0.1:8088 web/tests/bmvj_local_router.php
// Uses real CGB front controller/auth/routes; only persistence is substituted.
if (PHP_SAPI !== 'cli-server') throw new RuntimeException('Local development server only');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$deviceAuth = in_array($path, ['/api/adapter/device-auth', '/api/adapter/device-auth.php'], true);
$download = in_array($path, ['/cgb/download', '/cgb/download.php'], true)
    && preg_match('#^/A4/CGB-BMVJ/(?:RomList\.cgb|h0000\.cgb|[0-9]{4}\.G[0-9]{3}\.cgb)$#', $_GET['name'] ?? '');
// The original SDK follows its authenticated catalog GET with an empty POST
// (HTTP/1.0, same GB00 Authorization). Preserve the real download controller's
// method handling: dispatch POST unchanged, without rewriting it into GET.
$allowedMethods = $download ? ['GET', 'HEAD', 'POST'] : ['GET', 'HEAD'];
if ((!$deviceAuth && !$download) || !in_array($_SERVER['REQUEST_METHOD'], $allowedMethods, true)) {
    http_response_code(404);
    return;
}

require_once __DIR__ . '/bmvj_local_database.php';
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);
$stateDir = getenv('BMVJ_STATE_DIR') ?: sys_get_temp_dir() . '/reon-bmvj-local';
if (!is_dir($stateDir) && !mkdir($stateDir, 0700, true)) throw new RuntimeException('Cannot create local state');
session_save_path($stateDir);
$pdo = new PDO('sqlite:' . $stateDir . '/fixture.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS sys_users (
    id INTEGER PRIMARY KEY, dion_ppp_id TEXT UNIQUE, log_in_password TEXT,
    banned_at TEXT, custom_bmvj_opt_in INTEGER, money_spent INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE IF NOT EXISTS bmvj_custom_games (
    game_id TEXT PRIMARY KEY, blocks_needed INTEGER, category_icon INTEGER,
    min_level_react INTEGER, min_level_smart INTEGER, min_level_sense INTEGER,
    min_hidden_level_a INTEGER, min_hidden_level_b INTEGER, title BLOB, description BLOB,
    download_filename TEXT, minigame_type INTEGER, price_yen INTEGER, game_binary BLOB, is_active INTEGER)');
// Public, disposable fixture accounts; never real credentials/configuration.
$pdo->exec("INSERT OR IGNORE INTO sys_users (id,dion_ppp_id,log_in_password,custom_bmvj_opt_in)
    VALUES (7,'g000000007','fixture',1),(8,'g000000008','fixture',0)");
$pdo->exec('CREATE TABLE IF NOT EXISTS sys_device_authorization (user_id INTEGER PRIMARY KEY, device_auth_key BLOB)');
$pdo->exec('CREATE TABLE IF NOT EXISTS sys_device_counter (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, device_id TEXT, counter INTEGER DEFAULT 0,
    authorized INTEGER DEFAULT 0, authorized_until TEXT, blocked INTEGER DEFAULT 0,
    last_query_counter INTEGER DEFAULT 0, last_ip TEXT, last_seen_at TEXT, UNIQUE(user_id,device_id))');
$keyStmt = $pdo->prepare('INSERT OR IGNORE INTO sys_device_authorization VALUES (?,?)');
foreach ([7, 8] as $userId) $keyStmt->execute([$userId, str_repeat(chr(0x42), 32)]);
$GLOBALS['db'] = new BmvjLocalDatabase($pdo);
// Prevent accidental loading of the workspace's real config.json.
$GLOBALS['config'] = ['local_bmvj_fixture' => true];
if ($download) {
    // Local-only response evidence; never log Authorization or request data.
    ob_start();
    register_shutdown_function(static function () use ($pdo, $stateDir): void {
        $body = ob_get_contents();
        if ($body === false) return;
        $userId = $_SESSION['utility_authed_user_id'] ?? $_SESSION['userId'] ?? null;
        $optIn = null;
        if ($userId !== null) {
            $stmt = $pdo->prepare('SELECT custom_bmvj_opt_in FROM sys_users WHERE id=?');
            $stmt->execute([(int)$userId]);
            $value = $stmt->fetchColumn();
            $optIn = $value === false ? null : (int)$value;
        }
        $name = $_GET['name'] ?? '';
        $status = http_response_code() ?: 200;
        $catalog = $status === 200 && $name === '/A4/CGB-BMVJ/RomList.cgb' && $body !== '';
        $record = [
            'time_utc' => gmdate('c'), 'method' => $_SERVER['REQUEST_METHOD'],
            'path' => $name, 'status' => $status, 'account' => $userId,
            'custom_opt_in' => $optIn, 'body_length' => strlen($body),
            'body_sha256' => hash('sha256', $body),
            'catalog_count' => $catalog ? ord($body[0]) : null,
        ];
        if ($catalog) {
            $record['catalog_file'] = $stateDir . '/catalog-' . $_SERVER['REQUEST_METHOD'] . '-' . (int)$userId . '.bin';
            file_put_contents($record['catalog_file'], $body, LOCK_EX);
        }
        file_put_contents('/var/log/reon/bmvj-http.jsonl', json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
    });
}
if ($deviceAuth) {
    require_once dirname(__DIR__) . '/classes/DeviceAuthUtil.php';
    // Inject the test adapter without running DBUtil's real-config constructor.
    $reflection = new ReflectionClass(DBUtil::class);
    $local = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('db')->setValue($local, $GLOBALS['db']);
    $reflection->getProperty('instance')->setValue(null, $local);
    $endpoint = dirname(__DIR__) . '/htdocs/api/adapter/device-auth.php';
    chdir(dirname($endpoint));
    require $endpoint;
    return;
}
$raw = file_get_contents(__DIR__ . '/fixtures/bmvj/input_tester.flash');
if (hash('sha256', $raw) !== 'e1f4e3c297448b4308eb45efc8107522ec0716d2961c5b1bdc5a9d22bc56881b') {
    throw new RuntimeException('Raw fixture changed');
}
$body = "SYNTHETIC-BMVJ-HTTP-BODY\0" . $raw;
$game = [
    'game_id' => 'G001', 'blocks_needed' => 1, 'category_icon' => 0,
    'min_level_react' => 0, 'min_level_smart' => 0, 'min_level_sense' => 0,
    'min_hidden_level_a' => 0, 'min_hidden_level_b' => 0,
    'title' => 'PAD TEST', 'description' => 'LOCAL TRANSPORT TEST',
    'download_filename' => '0000.G001.cgb', 'minigame_type' => 1, 'price_yen' => 0,
];
if ($bodyFile = getenv('BMVJ_BODY')) {
    $body = file_get_contents($bodyFile);
    $hash = getenv('BMVJ_BODY_SHA256');
    $metadataFile = getenv('BMVJ_METADATA');
    if ($body === false || !$hash || !hash_equals($hash, hash('sha256', $body)) || !$metadataFile) {
        throw new RuntimeException('External complete body requires matching SHA256 and metadata');
    }
    $metadata = json_decode(file_get_contents($metadataFile), true, 512, JSON_THROW_ON_ERROR);
    foreach (['title', 'description'] as $key) {
        $encoded = $metadata[$key . '_hex'] ?? '';
        if ($encoded === '' || !ctype_xdigit($encoded) || strlen($encoded) % 2) {
            throw new RuntimeException('Metadata requires nonempty game-encoded ' . $key . '_hex');
        }
        $metadata[$key] = hex2bin($encoded);
    }
    $game = array_replace($game, array_intersect_key($metadata, $game));
}
if (preg_match('/^G[0-9]{3}$/', $game['game_id']) !== 1
    || $game['download_filename'] !== sprintf('%04d.%s.cgb', $game['price_yen'], $game['game_id'])) {
    throw new RuntimeException('Fixture ID/filename/price mismatch');
}
$columns = array_keys($game);
$columns[] = 'game_binary';
$columns[] = 'is_active';
$values = array_values($game);
$values[] = $body;
$values[] = 1;
$pdo->exec('DELETE FROM bmvj_custom_games');
$stmt = $pdo->prepare('INSERT INTO bmvj_custom_games (' . implode(',', $columns) . ') VALUES ('
    . implode(',', array_fill(0, count($columns), '?')) . ')');
$stmt->execute($values);
require dirname(__DIR__) . '/htdocs/cgb/download.php';
