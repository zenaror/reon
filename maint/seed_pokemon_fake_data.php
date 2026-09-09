#!/usr/bin/env php
<?php
// SPDX-License-Identifier: MIT
//
// Seeds synthetic-but-valid Pokémon Crystal content so the Battle Tower honor
// roll and the Trade Corner are not empty before real traffic arrives:
//
//   * Battle Tower: rows in bxt_battle_tower_records shaped exactly like a real
//     upload (7-byte name, class derived from the Trainer ID the way the ROM's
//     GetMobileOTTrainerClass does, three 59-byte party mons, three 8-byte Easy
//     Chat messages, plausible battle stats). The daily reon-pokemon-battle job
//     then promotes leaders to the honor roll and rebuilds the downloadable
//     rooms from them, same as for real records.
//   * Trade Corner: deposits in bxt_exchange (65-byte mon = party struct + OT
//     name + nickname, zeroed 47-byte mail, no mail item held). Offered and
//     requested species are kept disjoint so seeded deposits never match each
//     other, and any deposit that would match an existing real row is dropped.
//
// Pokémon come from the placeholder trainer corpus the server already serves
// for empty rooms (web/cgb/pokemon/battle_tower_trainers.php): each base mon is
// re-rolled with fresh DVs, its stats recomputed with the Gen II formula, and
// the result run through the same legality checker real uploads go through.
// Only mons the checker accepts are used.
//
// Everything is attributed to bot accounts (see BOT_ACCOUNTS) so it can be
// removed again with --purge (honor-roll rows carry no account id, so a
// fingerprint manifest is kept for those).
//
// Usage (from the repo root, on the server):
//   POKEMON_LEGALITY_BIN=... php maint/seed_pokemon_fake_data.php --create-account --dry-run
//   php maint/seed_pokemon_fake_data.php --create-account
//   php maint/seed_pokemon_fake_data.php --purge

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(1);
}

define('CORE_PATH', dirname(__DIR__) . '/web/cgb');
require_once CORE_PATH . '/database.php';
require_once CORE_PATH . '/pokemon/battle_tower_trainers.php';
require_once dirname(__DIR__) . '/web/scripts/bxt_decode_helpers.php';
require_once dirname(__DIR__) . '/web/scripts/bxt_legality_check.php';
require_once dirname(__DIR__) . '/web/scripts/bxt_value_validation.php';
require_once __DIR__ . '/gen2_base_stats.php';

$opts = getopt('', [
    'dry-run', 'purge', 'create-account', 'rebalance', 'help',
    'bt-per-room:', 'bt-rooms:', 'tc:', 'rankings:', 'pool:', 'seed:',
    'cache:', 'manifest:', 'skip:',
]);

if (isset($opts['help'])) {
    echo <<<TXT
Options:
  --dry-run            generate and validate everything, write nothing
  --purge              delete everything attributed to the bot accounts (and honor-roll rows from the manifest)
  --create-account     create the bot accounts that do not exist yet
  --rebalance          only redistribute existing bot deposits over the bot accounts (no inserts)
  --bt-rooms=N|all     rooms per level to fill (default: all = 20)
  --bt-per-room=N      records per level/room (default: 7)
  --tc=N               Trade Corner deposits (default: 30)
  --rankings=N         Pokémon News ranking players, 3 rows each, one per current category (default: 0)
  --pool=N             DV-rerolled mons to validate per level (default: 50)
  --seed=N             RNG seed (default: random)
  --skip=L:R[,L:R]     level:room pairs to leave alone (default: 1:0, the only room with a real record)
  --cache=PATH         legality cache (default: ~/.reon_seed_legality.json)
  --manifest=PATH      manifest of inserted rows (default: ~/.reon_seed_manifest.json)

TXT;
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$btPerRoom = (int)($opts['bt-per-room'] ?? 7);
$btRooms = ($opts['bt-rooms'] ?? 'all') === 'all' ? 20 : (int)$opts['bt-rooms'];
$tcCount = (int)($opts['tc'] ?? 30);
$rankingCount = (int)($opts['rankings'] ?? 0);
$poolSize = (int)($opts['pool'] ?? 50);
$home = getenv('HOME') ?: sys_get_temp_dir();
$cachePath = $opts['cache'] ?? ($home . '/.reon_seed_legality.json');
$manifestPath = $opts['manifest'] ?? ($home . '/.reon_seed_manifest.json');
$skipPairs = [];
foreach (explode(',', $opts['skip'] ?? '1:0') as $pair) {
    if (preg_match('/^(\d+):(\d+)$/', trim($pair), $m)) {
        $skipPairs[$m[1] . ':' . $m[2]] = true;
    }
}
if (isset($opts['seed'])) {
    mt_srand((int)$opts['seed']);
}

if (getenv('POKEMON_LEGALITY_BIN') === false) {
    $publish = dirname(__DIR__) . '/app/pokemon-legality/LegalityCheckerConsole/publish/LegalityCheckerConsole';
    if (is_file($publish)) {
        putenv('POKEMON_LEGALITY_BIN=' . $publish);
    }
}

const REGION = 'e';

// Bot accounts. Trade Corner cards show a warning derived from the owning
// account's trade_region_allowlist, so deposits are spread over three accounts
// to exercise all three states: everything allowed, no Japanese, own game only.
const BOT_ACCOUNTS = [
    ['username' => 'reonbot',     'local' => 'reonbot',  'allowlist' => 'efdsipuj',  'share' => 60],
    ['username' => 'reonbot-eu',  'local' => 'reonbot2', 'allowlist' => 'efdsipu,j', 'share' => 27],
    ['username' => 'reonbot-own', 'local' => 'reonbot3', 'allowlist' => 'e,fdsipuj', 'share' => 13],
];
const MAIL_ITEMS = [0x9E, 0xB5, 0xB6, 0xB7, 0xB8, 0xB9, 0xBA, 0xBB, 0xBC, 0xBD];

// data/trainers/gendered_trainers.asm, in table order; class ids per
// constants/trainer_constants.asm (same ids bxt_encoding.json decodes).
const MALE_TRAINERS = [47, 22, 23, 24, 30, 32, 36, 37, 38, 40, 41, 43, 44, 48, 50, 52, 54, 27, 58, 49, 59, 65, 56, 45, 20];
const FEMALE_TRAINERS = [57, 25, 29, 33, 34, 39, 53, 60, 62, 28];

// engine/events/battle_tower/get_trainer_class.asm GetMobileOTTrainerClass,
// called with wPlayerID and wPlayerGender (battle_tower.asm:113-115).
function bt_trainer_class(int $tid, bool $female): int {
    $c = (($tid >> 8) & 0xFF) ^ ($tid & 0xFF);
    if ($c !== 0) {
        $c >>= 2;
        do {
            $c >>= 1;
        } while ($c >= count(MALE_TRAINERS) - 1);
        $c++;
    }
    if (!$female) {
        return MALE_TRAINERS[$c];
    }
    if ($c !== 0) {
        do {
            $c >>= 1;
        } while ($c >= count(FEMALE_TRAINERS) - 1);
        $c++;
    }
    return FEMALE_TRAINERS[$c];
}

function gen2_encode(string $text): string {
    $out = '';
    foreach (str_split($text) as $ch) {
        $o = ord($ch);
        if ($o >= 0x41 && $o <= 0x5A) {
            $out .= chr(0x80 + $o - 0x41);
        } elseif ($o >= 0x61 && $o <= 0x7A) {
            $out .= chr(0xA0 + $o - 0x61);
        } elseif ($o >= 0x30 && $o <= 0x39) {
            $out .= chr(0xF6 + $o - 0x30);
        } elseif ($ch === ' ') {
            $out .= "\x7F";
        } elseif ($ch === '.') {
            $out .= "\xE8";
        } elseif ($ch === '-') {
            $out .= "\xE3";
        } else {
            throw new RuntimeException("cannot encode '$ch'");
        }
    }
    return $out;
}

function padded_name(string $text, int $len): string {
    $b = gen2_encode($text);
    if (strlen($b) > $len) {
        throw new RuntimeException("name too long: $text");
    }
    return str_pad($b, $len, "\x50");
}

function gen2_stat(int $base, int $dv, int $statExp, int $level, bool $isHp): int {
    $x = intdiv((int)ceil(sqrt($statExp)), 4);
    $v = intdiv((($base + $dv) * 2 + $x) * $level, 100);
    return $isHp ? $v + $level + 10 : $v + 5;
}

// Re-roll DVs on a 48-byte party struct and recompute the six stats.
function reroll_party_struct(string $party, array $baseStats): string {
    $species = ord($party[0]);
    if (!isset($baseStats[$species])) {
        throw new RuntimeException("no base stats for species $species");
    }
    [$bHp, $bAtk, $bDef, $bSpe, $bSpa, $bSpd] = $baseStats[$species];
    $atk = mt_rand(0, 15);
    $def = mt_rand(0, 15);
    $spe = mt_rand(0, 15);
    $spc = mt_rand(0, 15);
    $hpDv = (($atk & 1) << 3) | (($def & 1) << 2) | (($spe & 1) << 1) | ($spc & 1);
    $level = ord($party[31]);
    $exp = unpack('nhp/natk/ndef/nspe/nspc', substr($party, 11, 10));

    $stats = [
        gen2_stat($bHp, $hpDv, $exp['hp'], $level, true),
        gen2_stat($bAtk, $atk, $exp['atk'], $level, false),
        gen2_stat($bDef, $def, $exp['def'], $level, false),
        gen2_stat($bSpe, $spe, $exp['spe'], $level, false),
        gen2_stat($bSpa, $spc, $exp['spc'], $level, false),
        gen2_stat($bSpd, $spc, $exp['spc'], $level, false),
    ];

    $party[21] = chr(($atk << 4) | $def);
    $party[22] = chr(($spe << 4) | $spc);
    $party[27] = chr(mt_rand(70, 255));
    $party = substr($party, 0, 34)
        . pack('n7', $stats[0], $stats[0], $stats[1], $stats[2], $stats[3], $stats[4], $stats[5])
        . substr($party, 48);
    return $party;
}

function set_ot_id(string $blob, int $tid): string {
    return substr($blob, 0, 6) . pack('n', $tid) . substr($blob, 8);
}

// ---------------------------------------------------------------------------
// Legality checks, cached by blob hash so re-runs are cheap.
// ---------------------------------------------------------------------------
$legalityCache = is_file($cachePath) ? (json_decode((string)file_get_contents($cachePath), true) ?: []) : [];
$legalityCalls = 0;
function check_legal(string $blob): array {
    global $legalityCache, $legalityCalls;
    $key = md5($blob);
    if (isset($legalityCache[$key])) {
        return $legalityCache[$key];
    }
    $legalityCalls++;
    try {
        [$ok, $details] = legality_check_pk2_bytes_with_details($blob, function () {});
    } catch (Throwable $e) {
        $ok = false;
        $details = ['error' => $e->getMessage()];
    }
    $legalityCache[$key] = ['ok' => (bool)$ok, 'details' => is_array($details) ? $details : []];
    return $legalityCache[$key];
}
function save_cache(): void {
    global $legalityCache, $cachePath;
    file_put_contents($cachePath, json_encode($legalityCache));
}

// ---------------------------------------------------------------------------
// DB + bot accounts (resolved before the slow pool work so account problems
// fail fast, and so --rebalance/--purge never touch the legality checker).
// ---------------------------------------------------------------------------
$db = connectMySQL();
$db->set_charset('utf8mb4');
$config = getConfig();
$dionDomain = $config['email_domain_dion'] ?? 'reon.dion.ne.jp';

$accounts = []; // BOT_ACCOUNTS entries + 'id' + 'email'
foreach (BOT_ACCOUNTS as $spec) {
    $stmt = $db->prepare('select id from sys_users where username = ?');
    $stmt->bind_param('s', $spec['username']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row && isset($opts['create-account'])) {
        if ($dryRun) {
            echo "[dry-run] would create sys_users row username={$spec['username']} allowlist={$spec['allowlist']}\n";
            $row = ['id' => 0];
        } else {
            $stmt = $db->prepare('insert into sys_users (email, username, password, dion_email_local, trade_region_allowlist, timezone, is_admin, passport_seen_at) values (NULL, ?, NULL, ?, ?, ?, 0, now())');
            $tz = '+0000';
            $stmt->bind_param('ssss', $spec['username'], $spec['local'], $spec['allowlist'], $tz);
            $stmt->execute();
            $row = ['id' => $db->insert_id];
            $stmt->close();
            echo "Created bot account id={$row['id']} username={$spec['username']} allowlist={$spec['allowlist']}\n";
        }
    }
    if (!$row) {
        fwrite(STDERR, "bot account '{$spec['username']}' not found; pass --create-account\n");
        exit(1);
    }
    $spec['id'] = (int)$row['id'];
    $spec['email'] = $spec['local'] . '@' . $dionDomain;
    $accounts[] = $spec;
}
$accountIds = array_map(fn($a) => $a['id'], $accounts);
$accountIdList = implode(',', array_map('intval', $accountIds));
$primaryAccountId = $accounts[0]['id'];

function pick_account(array $accounts): array {
    $total = array_sum(array_map(fn($a) => $a['share'], $accounts));
    $roll = mt_rand(1, $total);
    foreach ($accounts as $a) {
        $roll -= $a['share'];
        if ($roll <= 0) {
            return $a;
        }
    }
    return $accounts[0];
}

// ---------------------------------------------------------------------------
// --rebalance: spread the existing bot deposits over the accounts by share.
// ---------------------------------------------------------------------------
if (isset($opts['rebalance'])) {
    $ids = [];
    $res = $db->query("select id from bxt_exchange where account_id in ($accountIdList) order by id");
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $total = array_sum(array_map(fn($a) => $a['share'], $accounts));
    $assigned = [];
    $cursor = 0;
    foreach ($accounts as $i => $a) {
        $n = $i === count($accounts) - 1
            ? count($ids) - $cursor
            : (int)round(count($ids) * $a['share'] / $total);
        $assigned[$a['id']] = array_slice($ids, $cursor, $n);
        $cursor += $n;
    }
    $stmt = $db->prepare('update bxt_exchange set account_id = ?, email = ? where id = ?');
    foreach ($accounts as $a) {
        foreach ($assigned[$a['id']] as $id) {
            if (!$dryRun) {
                $stmt->bind_param('isi', $a['id'], $a['email'], $id);
                $stmt->execute();
            }
        }
        printf("%s %d deposits -> %s (id %d, allowlist %s)\n", $dryRun ? '[dry-run] would move' : 'moved',
            count($assigned[$a['id']]), $a['username'], $a['id'], $a['allowlist']);
    }
    $stmt->close();
    exit(0);
}

// ---------------------------------------------------------------------------
// --purge
// ---------------------------------------------------------------------------
if (isset($opts['purge'])) {
    $manifest = is_file($manifestPath) ? (json_decode((string)file_get_contents($manifestPath), true) ?: []) : [];
    $counts = [];
    foreach (['bxt_battle_tower_records', 'bxt_battle_tower_trainers', 'bxt_exchange', 'bxt_ranking'] as $table) {
        $counts[$table] = (int)$db->query("select count(*) c from `$table` where account_id in ($accountIdList)")->fetch_assoc()['c'];
        if (!$dryRun) {
            $db->query("delete from `$table` where account_id in ($accountIdList)");
        }
    }
    $hr = 0;
    foreach ($manifest['bt_fingerprints'] ?? [] as $fp) {
        $stmt = $db->prepare('select count(*) c from bxt_battle_tower_honor_roll where player_name = ? and class = ? and pokemon1 = ?');
        $name = hex2bin($fp['name']);
        $p1 = hex2bin($fp['p1']);
        $stmt->bind_param('sis', $name, $fp['class'], $p1);
        $stmt->execute();
        $hr += (int)$stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();
        if (!$dryRun) {
            $stmt = $db->prepare('delete from bxt_battle_tower_honor_roll where player_name = ? and class = ? and pokemon1 = ?');
            $stmt->bind_param('sis', $name, $fp['class'], $p1);
            $stmt->execute();
            $stmt->close();
        }
    }
    $verb = $dryRun ? 'would delete' : 'deleted';
    foreach ($counts as $t => $c) {
        echo "$verb $c rows from $t\n";
    }
    echo "$verb $hr rows from bxt_battle_tower_honor_roll (by manifest fingerprint)\n";
    if (!$dryRun && is_file($manifestPath)) {
        unlink($manifestPath);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Placeholder corpus (EN) -> per-level base mons and message pools.
// ---------------------------------------------------------------------------
$baseMons = [];      // level => list of 59-byte blobs
$messages = ['message_start' => [], 'message_win' => [], 'message_lose' => []];
for ($level = 0; $level < 10; $level++) {
    for ($n = 0; $n < 7; $n++) {
        $t = getBattleTowerPlaceholderTrainerForRegion(REGION, $level, $n);
        foreach (['pokemon1', 'pokemon2', 'pokemon3'] as $k) {
            if (isset($t[$k]) && is_string($t[$k]) && strlen($t[$k]) === 59) {
                $baseMons[$level][bin2hex($t[$k])] = $t[$k];
            }
        }
        foreach ($messages as $k => $_) {
            if (isset($t[$k]) && is_string($t[$k]) && strlen($t[$k]) === 8) {
                $messages[$k][bin2hex($t[$k])] = $t[$k];
            }
        }
    }
    $baseMons[$level] = array_values($baseMons[$level] ?? []);
}
foreach ($messages as $k => $v) {
    $messages[$k] = array_values($v);
}

echo "Base corpus: " . array_sum(array_map('count', $baseMons)) . " mons, "
    . implode('/', array_map('count', $messages)) . " start/win/lose messages\n";

// ---------------------------------------------------------------------------
// Validated DV-rerolled pool per level.
// ---------------------------------------------------------------------------
$pool = []; // level => list of ['blob59' => ..., 'species' => n, 'details' => [...]]
foreach ($baseMons as $level => $mons) {
    $pool[$level] = [];
    $attempts = 0;
    while (count($pool[$level]) < $poolSize && $attempts < $poolSize * 3) {
        $attempts++;
        $base = $mons[$attempts % count($mons)];
        $party = reroll_party_struct(substr($base, 0, 48), $BASE_STATS);
        $blob = $party . substr($base, 48, 11);
        $r = check_legal($blob);
        if (!$r['ok']) {
            continue;
        }
        $pool[$level][] = ['blob59' => $blob, 'species' => ord($blob[0]), 'details' => $r['details']];
    }
    printf("L%-3d pool: %d legal of %d rerolls\n", ($level + 1) * 10, count($pool[$level]), $attempts);
}
save_cache();

// ---------------------------------------------------------------------------
// Names.
// ---------------------------------------------------------------------------
$names = [
    'KENJI', 'YUKI', 'HARUTO', 'AKIRA', 'SORA', 'MEI', 'RIN', 'HINATA', 'TAKUMI', 'SAKURA',
    'DAIKI', 'AOI', 'NANAMI', 'KAITO', 'MIYU', 'RYO', 'SHO', 'TSUBASA', 'KOHAKU', 'MOMO',
    'YUNA', 'AYA', 'GORO', 'TARO', 'HANA', 'NATSUKI', 'YOKO', 'KENTA', 'SHUN', 'TAIGA',
    'NAO', 'RIKU', 'HIRO', 'JUN', 'DAI', 'GON', 'SATOSHI', 'MASATO', 'HIKARI', 'REI',
    'ARTHUR', 'LUCAS', 'PEDRO', 'MATEUS', 'GABI', 'JULIA', 'BRUNO', 'FELIPE', 'ANDRE', 'THIAGO',
    'LEO', 'ISA', 'LARISSA', 'MARINA', 'DIEGO', 'ENZO', 'LIVIA', 'CAIO', 'VITOR', 'IGOR',
    'HELENA', 'ALICE', 'BIA', 'DUDA', 'GUI', 'CADU', 'NINA', 'TETE', 'JOAO', 'PAULO',
    'ETHAN', 'NOAH', 'LIAM', 'MASON', 'LOGAN', 'OWEN', 'EMMA', 'OLIVIA', 'AVA', 'MIA',
    'CHLOE', 'ZOE', 'LILY', 'ELLA', 'GRACE', 'JACK', 'HARRY', 'OSCAR', 'ALFIE', 'FREDDIE',
    'POPPY', 'DAISY', 'ROSIE', 'MAX', 'SAM', 'BEN', 'TOM', 'JOE', 'DAN', 'ALEX',
    'JAMIE', 'KIM', 'LEE', 'RAY', 'JAY', 'MARCO', 'LUCA', 'GIULIA', 'SOFIA', 'MATEO',
    'PABLO', 'CARLOS', 'LOLA', 'INES', 'NOEMIE', 'LOUIS', 'HUGO', 'LENA', 'FINN', 'JONAS',
    'DRAGO', 'SHADOWX', 'BLAZE', 'FROST', 'NOVA', 'PIXEL', 'GLITCH', 'TURBO', 'NEO', 'ZERO',
    'ACE', 'DUKE', 'HUNTER', 'SLASH', 'STORM', 'RAVEN', 'WOLF', 'TIGER', 'HAWK', 'VIPER',
    'COBRA', 'GHOST', 'NINJA', 'SAMURAI', 'RONIN', 'SHOGUN', 'KAGE', 'ONI', 'KITSUNE', 'TANUKI',
    'SENSEI', 'MOCHI', 'DANGO', 'RAMEN', 'SUSHI', 'UDON', 'MISO', 'WAFFLE', 'TACO', 'BANANA',
    'KOJI', 'RINRIN', 'MAXI', 'YUKIO', 'LEON', 'SORATA', 'AYANE', 'NEON', 'GAMEBOY', 'MOBILE',
];

// ---------------------------------------------------------------------------
// Battle Tower records.
// ---------------------------------------------------------------------------
$usedIds = [];
function fresh_ids(array &$used): array {
    do {
        $tid = mt_rand(1, 65535);
        $sid = mt_rand(0, 65535);
    } while (isset($used["$tid:$sid"]));
    $used["$tid:$sid"] = true;
    return [$tid, $sid];
}

function pick_team(array $levelPool): array {
    $team = [];
    $species = [];
    $tries = 0;
    while (count($team) < 3 && $tries++ < 200) {
        $m = $levelPool[mt_rand(0, count($levelPool) - 1)];
        if (isset($species[$m['species']])) {
            continue;
        }
        $species[$m['species']] = true;
        $team[] = $m;
    }
    if (count($team) < 3) {
        throw new RuntimeException('could not assemble 3 distinct species');
    }
    return $team;
}

function battle_stats(int $level): array {
    $roll = mt_rand(1, 100);
    if ($roll <= 35) {
        $ntd = 7;
    } elseif ($roll <= 85) {
        $ntd = mt_rand(2, 6);
    } else {
        $ntd = mt_rand(0, 1);
    }
    $turns = $ntd * 3 + mt_rand($ntd * 2, $ntd * 9 + 3) + ($ntd < 7 ? mt_rand(2, 12) : 0);
    $fainted = $ntd === 7 ? [0, 0, 0, 1, 1, 2][mt_rand(0, 5)] : mt_rand(3, 6);
    $avgHp = 25 * ($level + 1) + 8;
    $dmg = (int)round($fainted * $avgHp * (mt_rand(95, 130) / 100) + $ntd * $avgHp * (mt_rand(25, 90) / 100));
    return [$ntd, min($turns, 65535), min($dmg, 65535), $fainted];
}

$btRecords = [];
$skipped = 0;
for ($level = 0; $level < 10; $level++) {
    if (count($pool[$level]) < 3) {
        echo "L" . (($level + 1) * 10) . ": pool too small, skipping level\n";
        continue;
    }
    for ($room = 0; $room < $btRooms; $room++) {
        if (isset($skipPairs["$level:$room"])) {
            $skipped++;
            continue;
        }
        $roomNames = [];
        for ($i = 0; $i < $btPerRoom; $i++) {
            do {
                $name = $names[mt_rand(0, count($names) - 1)];
            } while (isset($roomNames[$name]));
            $roomNames[$name] = true;

            [$tid, $sid] = fresh_ids($usedIds);
            $female = mt_rand(1, 100) <= 40;
            $class = bt_trainer_class($tid, $female);
            $team = pick_team($pool[$level]);
            $msgs = [];
            foreach ($messages as $k => $list) {
                $msgs[$k] = $list[mt_rand(0, count($list) - 1)];
            }

            $errors = [];
            $tries = 0;
            do {
                [$ntd, $turns, $dmg, $fainted] = battle_stats($level);
                $errors = [];
                $valid = bxt_validate_battle_tower_record_row(REGION, $msgs['message_start'], $msgs['message_win'], $msgs['message_lose'], $ntd, $turns, $dmg, $fainted, $level, $errors);
            } while (!$valid && $tries++ < 60);
            if (!$valid) {
                fwrite(STDERR, "L$level R$room: could not satisfy validator: " . json_encode($errors) . "\n");
                continue;
            }

            $rec = [
                'level' => $level, 'room' => $room, 'tid' => $tid, 'sid' => $sid,
                'name' => padded_name($name, 7), 'name_text' => $name, 'class' => $class,
                'ntd' => $ntd, 'turns' => $turns, 'dmg' => $dmg, 'fainted' => $fainted,
                'age' => mt_rand(0, 36 * 3600),
                'mons' => [], 'msgs' => $msgs,
            ];
            foreach ($team as $m) {
                $details = $m['details'];
                $details['TID'] = $tid;
                $rec['mons'][] = ['blob' => set_ot_id($m['blob59'], $tid), 'details' => $details];
            }
            $btRecords[] = $rec;
        }
    }
}
printf("Battle Tower: %d records prepared (%d level/room pairs skipped)\n", count($btRecords), $skipped);

// ---------------------------------------------------------------------------
// Trade Corner deposits.
// ---------------------------------------------------------------------------
$wantList = [
    152, 155, 158, 4, 7, 133, 131, 123, 127, 147, 246, 231, 216, 228, 215, 198, 200, 207, 214, 227,
    225, 220, 211, 235, 241, 234, 206, 193, 202, 203, 190, 179, 187, 172, 173, 174, 175, 177, 183, 122,
    124, 125, 126, 143, 113, 108, 114, 128, 115, 63, 66, 58, 37, 25, 35, 39, 95, 104, 111, 116,
    118, 138, 140, 142, 23, 27, 52, 54, 56, 60, 69, 74, 77, 79, 84, 86, 88, 90, 92, 96, 98, 102, 109,
];
$genderless = [81, 82, 100, 101, 120, 121, 132, 137, 144, 145, 146, 150, 151, 201, 233, 243, 244, 245, 249, 250, 251];

// Existing real deposits: a seeded deposit must never complete one of them.
$existing = [];
$res = $db->query('select offer_species, offer_gender, request_species, request_gender from bxt_exchange where account_id not in (' . $accountIdList . ')');
while ($row = $res->fetch_assoc()) {
    $existing[] = array_map('intval', $row);
}

function would_match(array $a, array $b): bool {
    return $a['offer_species'] === $b['request_species']
        && $a['request_species'] === $b['offer_species']
        && ($a['offer_gender'] === $b['request_gender'] || $b['request_gender'] === 3)
        && ($a['request_gender'] === $b['offer_gender'] || $a['request_gender'] === 3);
}

$tcDeposits = [];
$offeredSpecies = [];
$levelWeights = [0, 0, 0, 1, 1, 1, 2, 2, 3, 3, 4, 5, 6, 7, 8, 9];
$attempts = 0;
while (count($tcDeposits) < $tcCount && $attempts++ < $tcCount * 6) {
    $level = $levelWeights[mt_rand(0, count($levelWeights) - 1)];
    if (empty($pool[$level])) {
        continue;
    }
    $m = $pool[$level][mt_rand(0, count($pool[$level]) - 1)];
    $species = $m['species'];
    if ($species === 1 || in_array($species, $wantList, true)) {
        continue; // never offer Bulbasaur (the one real request), keep offer/request sets disjoint
    }
    [$tid, $sid] = fresh_ids($usedIds);
    $name = $names[mt_rand(0, count($names) - 1)];

    $party = set_ot_id(substr($m['blob59'], 0, 48), $tid);
    if (in_array(ord($party[1]), MAIL_ITEMS, true)) {
        $party[1] = "\x00";
    }
    $blob65 = $party . padded_name($name, 7) . substr($m['blob59'], 48, 10);
    $r = check_legal($blob65);
    if (!$r['ok']) {
        continue;
    }
    $pkGender = (int)($r['details']['gender'] ?? 2);
    $offerGender = [0 => 1, 1 => 2, 2 => 0][$pkGender] ?? 0;

    $reqSpecies = $wantList[mt_rand(0, count($wantList) - 1)];
    $reqGender = in_array($reqSpecies, $genderless, true) ? 3 : (mt_rand(1, 100) <= 70 ? 3 : mt_rand(1, 2));

    $dep = [
        'offer_species' => $species, 'offer_gender' => $offerGender,
        'request_species' => $reqSpecies, 'request_gender' => $reqGender,
        'tid' => $tid, 'sid' => $sid, 'name' => padded_name($name, 7), 'name_text' => $name,
        'pokemon' => $blob65, 'mail' => str_repeat("\x00", 47), 'details' => $r['details'],
        'age' => mt_rand(0, 30 * 3600), 'account' => pick_account($accounts),
    ];
    $conflict = false;
    foreach ($existing as $e) {
        if (would_match($dep, $e)) {
            $conflict = true;
            break;
        }
    }
    if ($conflict) {
        echo "dropping deposit that would complete a real trade ({$species} for {$reqSpecies})\n";
        continue;
    }
    $errors = [];
    if (!bxt_validate_exchange_row(REGION, $tid, $sid, $offerGender, $reqGender, $species, $blob65, $dep['mail'], $errors, $dep['name'])) {
        echo "dropping deposit rejected by validator: " . json_encode($errors) . "\n";
        continue;
    }
    $tcDeposits[] = $dep;
    $offeredSpecies[$species] = true;
}
save_cache();
printf("Trade Corner: %d deposits prepared (%d legality checks run this pass)\n", count($tcDeposits), $legalityCalls);

// ---------------------------------------------------------------------------
// Pokémon News rankings: one player = one row per category of the current
// vanilla news issue. Zip is three Gen II digits, message is a 12-byte Easy
// Chat field (we reuse the 8-byte Battle Tower pool, zero-padded).
// ---------------------------------------------------------------------------
$rankingRows = [];
$rankingNews = null;
if ($rankingCount > 0) {
    $res = $db->query("select id, ranking_category_1, ranking_category_2, ranking_category_3 from bxt_news where game_region = '" . REGION . "' and is_custom = 0 order by id desc limit 1");
    $rankingNews = $res->fetch_assoc();
    if (!$rankingNews) {
        fwrite(STDERR, "no vanilla news row for region " . REGION . "; skipping rankings\n");
    }
}
if ($rankingNews) {
    $catSizes = [];
    $res = $db->query("select id, size from bxt_ranking_categories");
    while ($row = $res->fetch_assoc()) {
        $catSizes[(int)$row['id']] = (int)$row['size'];
    }
    // Plausible ceilings per category id; anything else scales with its byte size.
    $scoreCeil = [5 => 35, 14 => 120, 16 => 400, 7 => 900, 9 => 300, 12 => 200, 4 => 250000, 38 => 999999];
    $categories = array_values(array_filter([(int)$rankingNews['ranking_category_1'], (int)$rankingNews['ranking_category_2'], (int)$rankingNews['ranking_category_3']]));
    $messagePool = array_merge($messages['message_start'], $messages['message_win'], $messages['message_lose']);
    $usedRankNames = [];
    for ($i = 0; $i < $rankingCount; $i++) {
        do {
            $name = $names[mt_rand(0, count($names) - 1)];
        } while (isset($usedRankNames[$name]) && count($usedRankNames) < count($names));
        $usedRankNames[$name] = true;
        [$tid, $sid] = fresh_ids($usedIds);
        $gender = mt_rand(1, 100) <= 55 ? 0 : 1;
        $age = mt_rand(9, 42);
        $pregion = mt_rand(1, 63);
        $zip = gen2_encode(sprintf('%03d', mt_rand(0, 999)));
        $msg = str_pad($messagePool[mt_rand(0, count($messagePool) - 1)], 12, "\x00");
        $age_days = mt_rand(0, 3 * 86400);
        foreach ($categories as $cat) {
            $ceil = $scoreCeil[$cat] ?? (($catSizes[$cat] ?? 2) >= 3 ? 500 : 60);
            // Skewed low: most players have modest numbers, a few stand out.
            $score = (int)round($ceil * pow(mt_rand(0, 1000) / 1000, 2.2));
            if (mt_rand(1, 100) <= 10) {
                $score = 0;
            }
            $rankingRows[] = [
                'news_id' => (int)$rankingNews['id'], 'category' => $cat, 'tid' => $tid, 'sid' => $sid,
                'name' => padded_name($name, 7), 'name_text' => $name, 'gender' => $gender, 'age' => $age,
                'pregion' => $pregion, 'zip' => $zip, 'msg' => $msg, 'score' => $score, 'age_seconds' => $age_days,
                'account' => pick_account($accounts),
            ];
        }
    }
    printf("Rankings: %d rows for %d players over categories %s (news %d)\n", count($rankingRows), $rankingCount, implode(',', $categories), (int)$rankingNews['id']);
}

// ---------------------------------------------------------------------------
// Report.
// ---------------------------------------------------------------------------
foreach (array_slice($btRecords, 0, 3) as $rec) {
    $mons = array_map(fn($m) => $m['details']['species'] . ' L' . $m['details']['level'], $rec['mons']);
    printf("  BT L%d R%03d %-7s class=%s(%d) tid=%d ntd=%d turns=%d dmg=%d fnt=%d [%s] start=\"%s\"\n",
        ($rec['level'] + 1) * 10, $rec['room'] + 1, $rec['name_text'],
        bxt_decode_trainer_class_for_region(REGION, $rec['class']), $rec['class'], $rec['tid'],
        $rec['ntd'], $rec['turns'], $rec['dmg'], $rec['fainted'], implode(', ', $mons),
        bxt_decode_player_message_for_region(REGION, $rec['msgs']['message_start']));
}
foreach (array_slice($rankingRows, 0, 3) as $r) {
    printf("  RK %-7s cat=%d score=%d %s age=%d %s zip=%s \"%s\"\n", $r['name_text'], $r['category'], $r['score'],
        bxt_decode_player_gender($r['gender']), $r['age'], bxt_decode_player_region(REGION, $r['pregion']),
        bxt_decode_exchange_player_zip(REGION, $r['zip']), bxt_decode_player_message_for_region(REGION, rtrim($r['msg'], "\x00")));
}
foreach (array_slice($tcDeposits, 0, 5) as $d) {
    printf("  TC %-7s offers %s(%d) g=%d L%d wants %s(%d) g=%d\n", $d['name_text'],
        bxt_decode_pokemon_species_for_region(REGION, $d['offer_species']), $d['offer_species'], $d['offer_gender'],
        $d['details']['level'], bxt_decode_pokemon_species_for_region(REGION, $d['request_species']),
        $d['request_species'], $d['request_gender']);
}

if ($dryRun) {
    echo "[dry-run] nothing written\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Insert.
// ---------------------------------------------------------------------------
$manifest = is_file($manifestPath) ? (json_decode((string)file_get_contents($manifestPath), true) ?: []) : [];
$manifest = array_merge(['bt_ids' => [], 'bt_fingerprints' => [], 'tc_ids' => [], 'ranking_rows' => 0], $manifest,
    ['account_ids' => $accountIds, 'updated_at' => date('c')]);
$db->begin_transaction();

$btSql = 'insert into bxt_battle_tower_records (game_region, room, level, level_decode, trainer_id, secret_id, player_name, player_name_decode, `class`, class_decode, '
    . 'pokemon1, pokemon1_decode, pokemon2, pokemon2_decode, pokemon3, pokemon3_decode, '
    . 'message_start, message_start_decode, message_win, message_win_decode, message_lose, message_lose_decode, '
    . 'num_trainers_defeated, num_turns_required, damage_taken, num_fainted_pokemon, account_id, `timestamp`) '
    . 'values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, now() - interval ? second)';
$stmt = $db->prepare($btSql);
foreach ($btRecords as $rec) {
    $region = REGION;
    $levelDecode = bxt_decode_simple_table('tower_level', $rec['level']);
    $nameDecode = bxt_decode_player_name_for_region(REGION, $rec['name']);
    $classDecode = bxt_decode_trainer_class_for_region(REGION, $rec['class']);
    $p = [];
    foreach ($rec['mons'] as $i => $m) {
        $p[$i] = $m['blob'];
        $p["d$i"] = bxt_format_legality_summary($m['details'], REGION);
    }
    $msd = bxt_decode_player_message_for_region(REGION, $rec['msgs']['message_start']);
    $mwd = bxt_decode_player_message_for_region(REGION, $rec['msgs']['message_win']);
    $mld = bxt_decode_player_message_for_region(REGION, $rec['msgs']['message_lose']);
    $stmt->bind_param('siisiississsssssssssssiiiiii',
        $region, $rec['room'], $rec['level'], $levelDecode, $rec['tid'], $rec['sid'],
        $rec['name'], $nameDecode, $rec['class'], $classDecode,
        $p[0], $p['d0'], $p[1], $p['d1'], $p[2], $p['d2'],
        $rec['msgs']['message_start'], $msd, $rec['msgs']['message_win'], $mwd, $rec['msgs']['message_lose'], $mld,
        $rec['ntd'], $rec['turns'], $rec['dmg'], $rec['fainted'], $primaryAccountId, $rec['age']);
    if (!$stmt->execute()) {
        $db->rollback();
        fwrite(STDERR, "insert failed: " . $stmt->error . "\n");
        exit(1);
    }
    $manifest['bt_ids'][] = $db->insert_id;
    $manifest['bt_fingerprints'][] = ['name' => bin2hex($rec['name']), 'class' => $rec['class'], 'p1' => bin2hex($p[0])];
}
$stmt->close();

$tcSql = 'insert into bxt_exchange (game_region, trainer_id, secret_id, offer_gender, offer_gender_decode, offer_species, offer_species_decode, '
    . 'request_gender, request_gender_decode, request_species, request_species_decode, player_name, player_name_decode, '
    . 'pokemon, pokemon_decode, mail, mail_decode, account_id, email, `timestamp`) '
    . 'values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, now() - interval ? second)';
$stmt = $db->prepare($tcSql);
foreach ($tcDeposits as $d) {
    $region = REGION;
    $ogd = bxt_decode_pokemon_gender_for_region(REGION, $d['offer_gender']);
    $osd = bxt_decode_pokemon_species_for_region(REGION, $d['offer_species']);
    $rgd = bxt_decode_pokemon_gender_for_region(REGION, $d['request_gender']);
    $rsd = bxt_decode_pokemon_species_for_region(REGION, $d['request_species']);
    $nameDecode = bxt_decode_exchange_player_name(REGION, $d['name']);
    $pkDecode = bxt_format_legality_summary($d['details'], REGION);
    $mailDecode = bxt_decode_mail_for_region(REGION, $d['mail']);
    $stmt->bind_param('siiisisisisssssssisi',
        $region, $d['tid'], $d['sid'], $d['offer_gender'], $ogd, $d['offer_species'], $osd,
        $d['request_gender'], $rgd, $d['request_species'], $rsd, $d['name'], $nameDecode,
        $d['pokemon'], $pkDecode, $d['mail'], $mailDecode, $d['account']['id'], $d['account']['email'], $d['age']);
    if (!$stmt->execute()) {
        $db->rollback();
        fwrite(STDERR, "insert failed: " . $stmt->error . "\n");
        exit(1);
    }
    $manifest['tc_ids'][] = $db->insert_id;
}
$stmt->close();

$rkSql = 'insert into bxt_ranking (game_region, news_id, category_id, category_id_decode, account_id, trainer_id, secret_id, '
    . 'player_name, player_name_decode, player_gender, player_gender_decode, player_age, player_region, player_region_decode, '
    . 'player_zip, player_zip_decode, player_message, player_message_decode, score, `timestamp`) '
    . 'values (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, now() - interval ? second)';
$stmt = $db->prepare($rkSql);
foreach ($rankingRows as $r) {
    $region = REGION;
    $catDecode = bxt_decode_ranking_category(REGION, $r['category']);
    $nameDecode = bxt_decode_player_name_for_region(REGION, $r['name']);
    $genderDecode = bxt_decode_player_gender($r['gender']);
    $regionDecode = bxt_decode_player_region(REGION, $r['pregion']);
    $zipDecode = bxt_decode_exchange_player_zip(REGION, $r['zip']);
    $msgDecode = bxt_decode_player_message_for_region(REGION, rtrim($r['msg'], "\x00"));
    $stmt->bind_param('siisiiissisiissssiii',
        $region, $r['news_id'], $r['category'], $catDecode, $r['account']['id'], $r['tid'], $r['sid'],
        $r['name'], $nameDecode, $r['gender'], $genderDecode, $r['age'], $r['pregion'], $regionDecode,
        $r['zip'], $zipDecode, $r['msg'], $msgDecode, $r['score'], $r['age_seconds']);
    if (!$stmt->execute()) {
        $db->rollback();
        fwrite(STDERR, "insert failed: " . $stmt->error . "\n");
        exit(1);
    }
    $manifest['ranking_rows']++;
}
$stmt->close();
$db->commit();

file_put_contents($manifestPath, json_encode($manifest));
printf("Inserted %d Battle Tower records, %d Trade Corner deposits and %d ranking rows this run (primary account_id=%d). Manifest: %s\n",
    count($btRecords), count($tcDeposits), count($rankingRows), $primaryAccountId, $manifestPath);
