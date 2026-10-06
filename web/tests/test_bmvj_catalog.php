<?php
// SPDX-License-Identifier: MIT
// Standalone offline test: php web/tests/test_bmvj_catalog.php
// The route path uses a small fake DB adapter and a synthetic HTTP body. This
// validates selection/auth/session/charging and byte pass-through only; the
// synthetic body is not a validated Net de Get download wrapper.

define('CORE_PATH', dirname(__DIR__) . '/cgb');
if (!defined('MYSQLI_ASSOC')) define('MYSQLI_ASSOC', 1);

final class FixtureBmvjResult
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_all(int $mode): array { return $this->rows; }
}

final class FixtureBmvjDatabase
{
    public bool $optedIn = true;
    public array $games = [];
    public int $chargedYen = 0;

    public function prepare(string $sql): FixtureBmvjStatement
    {
        return new FixtureBmvjStatement($this, $sql);
    }
}

final class FixtureBmvjStatement
{
    private array $params = [];
    private array $rows = [];

    public function __construct(private FixtureBmvjDatabase $db, private string $sql) {}
    public function bind_param(string $types, &...$params): bool
    {
        $this->params = [];
        foreach ($params as $value) $this->params[] = $value;
        return true;
    }
    public function execute(): bool
    {
        if (str_contains($this->sql, 'select custom_bmvj_opt_in')) {
            $this->rows = [['custom_bmvj_opt_in' => $this->db->optedIn ? 1 : 0]];
        } elseif (str_contains($this->sql, 'select game_id, blocks_needed')) {
            $this->rows = array_values(array_filter($this->db->games, fn($game) => !str_contains($this->sql, 'and is_custom = 0') || (int)($game['is_custom'] ?? 1) === 0));
        } elseif (str_contains($this->sql, 'select game_binary, price_yen')) {
            foreach ($this->db->games as $game) {
                if ($game['download_filename'] === ($this->params[0] ?? null) && (!str_contains($this->sql, 'and is_custom = 0') || (int)($game['is_custom'] ?? 1) === 0)) {
                    $this->rows = [['game_binary' => $game['game_binary'], 'price_yen' => $game['price_yen']]];
                    break;
                }
            }
        } elseif (str_contains($this->sql, 'update sys_users set money_spent')) {
            $this->db->chargedYen += (int)($this->params[0] ?? 0);
        } else {
            throw new RuntimeException('Unexpected BMVJ test SQL: ' . $this->sql);
        }
        return true;
    }
    public function get_result(): FixtureBmvjResult { return new FixtureBmvjResult($this->rows); }
    public function close(): void {}
}

$GLOBALS['db'] = new FixtureBmvjDatabase();
require_once dirname(__DIR__) . '/classes/BmvjUtil.php';
require_once dirname(__DIR__) . '/cgb/bmvj/routes.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function makeBaselineRecord(string $id): string
{
    return str_repeat("\0", 6) . $id . str_repeat("\0", 10) . "\x01X\x01Y\x0D0000." . $id . ".cgb\0\x01";
}

$fixturePath = __DIR__ . '/fixtures/bmvj/input_tester.flash';
$payload = file_get_contents($fixturePath);
if ($payload === false || strlen($payload) !== 168) {
    throw new RuntimeException('PAD TEST payload fixture is missing or has changed size');
}
$gameId = substr($payload, 9, 4);
$title = explode("\0", substr($payload, 0x0F), 2)[0];
$description = explode("\0", substr($payload, 0x24), 2)[0];
expectSame('G001', $gameId, 'payload game ID');
expectSame('PAD TEST', $title, 'payload game title');
expectSame(
    'e1f4e3c297448b4308eb45efc8107522ec0716d2961c5b1bdc5a9d22bc56881b',
    hash('sha256', $payload),
    'payload fixture checksum'
);

$officialRecord = makeBaselineRecord('G900');
$baseline = "\x01\x18\x00" . str_repeat("\0", 21) . $officialRecord;
$custom = [
    'game_id' => $gameId,
    'blocks_needed' => ord($payload[5]),
    'category_icon' => 0,
    'min_level_react' => 0,
    'min_level_smart' => 0,
    'min_level_sense' => 0,
    'min_hidden_level_a' => 0,
    'min_hidden_level_b' => 0,
    'title' => $title,
    'description' => $description,
    'download_filename' => '1234.' . $gameId . '.cgb',
    'minigame_type' => 1,
    'price_yen' => 1234,
];

$catalog = BmvjUtil::appendToCatalog($baseline, [$custom]);
if ($catalog === null) throw new RuntimeException('valid fixture catalog was rejected');
expectSame(2, ord($catalog[0]), 'catalog entry count');
$offsetOfficial = unpack('v', substr($catalog, 1, 2))[1];
$offsetCustom = unpack('v', substr($catalog, 3, 2))[1];
expectSame($officialRecord, substr($catalog, $offsetOfficial, strlen($officialRecord)), 'official record preserved');

$record = substr($catalog, $offsetCustom);
expectSame($gameId, substr($record, 6, 4), 'custom game ID');
expectSame([0, 0], array_values(unpack('v2', substr($record, 16, 4))), 'hidden level fields');
// Byte fixture derived from the original ROM's record readers, not Dan Docs.
expectSame('000000000100473030310000000000000000000008', bin2hex(substr($record, 0, 21)), 'ROM-derived record prefix');
$titleLength = ord($record[20]);
$cursor = 21 + $titleLength;
expectSame($title, substr($record, 21, $titleLength), 'title bytes');
$descriptionLength = ord($record[$cursor++]);
expectSame($description, substr($record, $cursor, $descriptionLength), 'description bytes');
$cursor += $descriptionLength;
$filenameLength = ord($record[$cursor++]);
expectSame('1234.' . $gameId . '.cgb', substr($record, $cursor, $filenameLength), 'server filename');
expectSame("\0\x01", substr($record, $cursor + $filenameLength, 2), 'filename terminator and type');

$thresholdGame = $custom;
$thresholdGame['min_level_react'] = 0x12;
$thresholdGame['min_level_smart'] = 0x34;
$thresholdGame['min_level_sense'] = 0x56;
$thresholdGame['min_hidden_level_a'] = 0x1234;
$thresholdGame['min_hidden_level_b'] = 0xABCD;
$thresholdCatalog = BmvjUtil::appendToCatalog($baseline, [$thresholdGame]);
if ($thresholdCatalog === null) throw new RuntimeException('threshold fixture rejected');
$thresholdOffset = unpack('v', substr($thresholdCatalog, 3, 2))[1];
$thresholdRecord = substr($thresholdCatalog, $thresholdOffset);
expectSame('12345600', bin2hex(substr($thresholdRecord, 0x0C, 4)), 'ROM category level offsets');
expectSame('3412cdab', bin2hex(substr($thresholdRecord, 0x10, 4)), 'ROM hidden threshold offsets');
expectSame(strlen($title), ord($thresholdRecord[0x14]), 'ROM text offset independent of thresholds');

$historical = file_get_contents(dirname(__DIR__) . '/cgb/download/A4/CGB-BMVJ/RomList.cgb');
$historicalOffset = unpack('v', substr($historical, 1, 2))[1];
expectSame(0x0B, ord($historical[$historicalOffset + 0x14]), 'historical first title length at ROM text offset');
expectSame("Mini\x10Game\x10!", substr($historical, $historicalOffset + 0x15, 0x0B), 'historical title bytes at ROM offset');

$withoutCustom = BmvjUtil::appendToCatalog($baseline, []);
if ($withoutCustom === null) throw new RuntimeException('baseline-only catalog was rejected');
expectSame(1, ord($withoutCustom[0]), 'opt-out catalog contains no custom entries');

$duplicate = $custom;
$duplicate['game_id'] = 'G900';
$duplicate['download_filename'] = '0000.G900.cgb';
$duplicate['price_yen'] = 0;
$deduplicated = BmvjUtil::appendToCatalog($baseline, [$duplicate]);
if ($deduplicated === null) throw new RuntimeException('duplicate ID fixture catalog was rejected');
expectSame(1, ord($deduplicated[0]), 'custom ID cannot shadow a baseline ID');

// Exercise the actual route functions with a fixture DB and an existing CGB
// session. The envelope is intentionally synthetic and tests transport bytes,
// not host parsing or natural minigame recognition.
$syntheticBody = "SYNTHETIC-BMVJ-HTTP-BODY\0" . $payload;
$custom['game_binary'] = $syntheticBody;
$GLOBALS['db']->games = [$custom];
$sessionId = 'bmvj-route-fixture-session-123456';
session_save_path(sys_get_temp_dir());
session_id($sessionId);
session_start();
$_SESSION = ['userId' => 7, 'type' => 'cgb', 'dionId' => 'fixture-account'];
session_write_close();
$_SERVER['HTTP_GB_AUTH_ID'] = $sessionId;

$_GET['name'] = '/A4/CGB-BMVJ/RomList.cgb';
ob_start();
expectSame(true, handleBmvjRoute('RomList.cgb'), 'catalog route handled');
$routedCatalog = ob_get_clean();
expectSame(5, ord($routedCatalog[0]), 'opted-in route adds the custom entry');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$_GET['name'] = '/A4/CGB-BMVJ/1234.' . $gameId . '.cgb';
ob_start();
expectSame(true, handleBmvjRoute('1234.' . $gameId . '.cgb'), 'payload route handled');
$servedBody = ob_get_clean();
expectSame($syntheticBody, $servedBody, 'route response is byte-identical to stored game_binary');
expectSame(1234, $GLOBALS['db']->chargedYen, 'eligible opted-in download charge');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$GLOBALS['db']->optedIn = false;
http_response_code(200);
$_GET['name'] = '/A4/CGB-BMVJ/RomList.cgb';
ob_start();
expectSame(true, handleBmvjRoute('RomList.cgb'), 'opt-out catalog route handled');
$optOutCatalog = ob_get_clean();
$routeBaseline = file_get_contents(dirname(__DIR__) . '/cgb/download/A4/CGB-BMVJ/RomList.cgb');
expectSame($routeBaseline, $optOutCatalog, 'opt-out route preserves only baseline catalog bytes');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$_GET['name'] = '/A4/CGB-BMVJ/1234.' . $gameId . '.cgb';
http_response_code(200);
ob_start();
expectSame(true, handleBmvjRoute('1234.' . $gameId . '.cgb'), 'opt-out payload route handled');
$optOutBody = ob_get_clean();
expectSame('', $optOutBody, 'opt-out cannot download custom payload');
expectSame(404, http_response_code(), 'opt-out custom download is not found');
expectSame(1234, $GLOBALS['db']->chargedYen, 'opt-out request is not charged');

echo "BMVJ catalog fixture checks passed\n";
