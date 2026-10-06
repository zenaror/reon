# Net de Get server integration

## Scope

REON serves the game's existing `A4/CGB-BMVJ` resources through the normal CGB
download front controller. Custom games are account opt-in content: the
official catalog remains present for every authenticated account, and active
REON entries are appended only when `sys_users.custom_bmvj_opt_in` is enabled.
The download endpoint checks the same opt-in before returning a custom payload.

## Binary contract used by this implementation

The URL/catalog concepts follow Dan Docs' Net de Get section and the local copy at
`MobileAdapterGB/MAGB-TestSuit/gbdk/docs/dandocs-magb.md`:

- `h0000.cgb` is the menu configuration; `RomList.cgb` is the catalog.
- `RomList.cgb` starts with an entry count and little-endian offsets to
  variable-length records. Its maximum catalog size is 78 entries.
- Each record includes required 8 KiB blocks, category, `Gxyz` ID, level
  thresholds, game-encoded title and description, server filename, and type.
- The minigame request filename starts with a four-digit service fee, followed
  by a period and the game ID, for example `0000.G999.cgb`.
- The payload is the game's download wrapper plus its compressed or
  uncompressed minigame data. The catalog code does not synthesize that binary.

The original ROM's record readers establish the following byte layout, which
corrects Dan Docs' missing four-byte leading reserved area:

| Record offset | Field | Disassembly evidence in A-selector 23 |
| --- | --- | --- |
| `00..03` | Reserved, zero in baseline; meaning unresolved | Historical records; preserve as zero for custom entries. |
| `04` | Required 8 KiB blocks | `47E9/47F5/4802` reads/limits blocks. |
| `05` | Category | `486F` passes this byte to `5527`. |
| `06..09` | Four-byte game ID | `4820` copies four bytes to `DB81`. |
| `0C..0E` | Three minimum category levels | `4972` loop reads three bytes. |
| `10..11`, `12..13` | Two hidden minimums, little endian | `49BD` filter. |
| `14`, `15...` | Title byte length, then title | `44D7/4AE0` text readers. |

The incorrect previous encoder placed the title at `10` and its text was
interpreted as large hidden-level requirements (`5008`/`4441` for PAD TEST).
Natural tests received five catalog entries but showed four, including with
empty SRAM/flash; the ROM-derived layout corrects that filtering. Tests include
nonzero level/hidden fields, real historical title bytes at `14`, and duplicate
ID checks at `06`. The first corrected local catalog is 434 bytes, count 5,
SHA-256 `8f072d41c39146380053abe0281835b4a639511a523cd5963bc67ef222aec043`;
authenticated GET and SDK POST responses match exactly. Natural rendering of
the corrected entry is a separate emulator check.

The server rebuilds the offset table when appending records and keeps each
baseline record's bytes intact. Invalid rows and IDs that collide with the
baseline are omitted. The baseline catalog in this checkout is a historical
fixture; it has not been verified as a current official catalog.

## Authentication and account selection

`RomList.cgb` uses the existing CGB utility-auth (`doAuth(2)`) path so the
server can identify the account before applying its preference. Price-prefixed
payload requests also use utility auth in the BMVJ route; the route checks
opt-in and the stored filename/price pair before it charges the account and
returns the payload. A guessed URL cannot charge an opted-out account.

The natural emulator run authenticated the original game through GB00, fetched
the personalized catalog and followed GET with empty POST on catalog and body.
Console hardware remains untested.
Do not infer that the menu, catalog rendering, download, Flash programming, or
gameplay works solely from the serializer test.

## Validation status

PHP 8.5 lint and `web/tests/test_bmvj_catalog.php` passed offline. The unit
fixture covers baseline preservation, record encoding, duplicate IDs, session
selection, opt-in/out, eligible charging, and exact synthetic body pass-through.
`web/tests/check_bmvj_local_http.py` additionally passed against the local HTTP
harness: real GB00 challenge/response, invalid password, opted-in catalog,
opted-out baseline/download refusal, static menu bytes, and exact response body.
A natural ROM trace captured a follow-up empty HTTP/1.0 POST to the catalog
URL, retaining GB00 Authorization, without Content-Length or body (207-byte
request, `/tmp/mgba-online-n7kfh2sm/tcp-send.bin`). The harness originally
refused POST, unlike the production front controller. It now dispatches POST
unchanged to that controller; HTTP tests reproduce the exact empty SDK POST
and compare its response bytes with the authenticated GET for both opt-in/out.
The game meaning of that follow-up remains under Disassembly review.

It also checks the real device-auth handler's signed query, authorize/deauthorize,
stale counter rejection and invalid signature rejection.

| Contract | Evidence and remaining limit |
| --- | --- |
| Catalog fields/offsets, `Gxyz`, price filename | ROM-derived record offsets plus offline/HTTP tests; mGBA reported natural visibility of all five entries after the encoder correction. |
| GB00 and per-account selection | Existing REON auth code exercised through HTTP; natural ROM GET401/GB00/GET200/POST200 passed locally. |
| Stored body transport | HTTP bytes equal the stored body (synthetic or exact Maker fixture); natural body GET/POST are independently logged with matching SHA-256. |
| Device-auth query/signatures | Existing `DeviceAuthUtil` and endpoint exercised with disposable fixture accounts; SQLite adapter does not validate MySQL migrations. |
| Host recognition/launch | Disassembly/mGBA reported natural NASU listing/launch in BOX1/BOX2 using preloaded local flash/SRAM fixtures. This bypasses download. |
| Host writer/persistence | Disassembly reported original caller/writer persisting artificial 4 KiB to a disposable sidecar. New synthetic traces also feed a wrapper in SRAM through the ROM producer and writer: 1,250-byte PAD TEST in modes 0/5, 32 operations, zero mismatches. Mode 0 reader skips 256 bytes per block of at most 512; mode 5 Maker compression passed without those extras. Acquisition HTTP/menu and final checksum validation are still bypassed; this is not an HTTP framing contract. |
| Complete body and natural acquisition | The exact mode 5 D800 body passed local HTTP and, per mGBA, natural ROM download/storage: 8 KiB at flash offset 0 equal the payload. Launch/input/exit/reopen are separate remaining gates. Other layouts/framing must not be generalized from this body. |

The checked-in 168-byte PAD TEST is an older raw flash fixture. The separately
reported 1,250-byte visually passing PAD TEST has not replaced it here. Neither
raw image is a verified complete HTTP body. No production deployment was used. The subsequent natural emulator download
evidence is recorded below; the old raw fixture was not used for that path.

## Reproducible local HTTP harness

Run from the repository root. Dependencies: Podman, the official PHP 8.5 CLI
image with PDO SQLite, mbstring and sessions, and Python 3 standard library.
The source mount is read-only; SQLite, sessions and logs are disposable. Only
local BMVJ downloads and device-auth are routed. No production configuration,
credentials, MySQL, mail, device provisioning or general website is used.
The local SQLite schema/adapter is test-only, and does not run the Phinx migration.

```sh
# Only if the image is not already available:
podman pull docker.io/library/php:8.5-cli
podman run -d --rm --name reon-bmvj-local --pull=never --network=host \
  --read-only --tmpfs /tmp --tmpfs /var/log/reon \
  -v "$PWD:/work:ro" -w /work docker.io/library/php:8.5-cli \
  php -d display_errors=0 -S 127.0.0.1:8088 web/tests/bmvj_local_router.php
python3 web/tests/check_bmvj_local_http.py
podman logs reon-bmvj-local
podman stop reon-bmvj-local
```

Public test accounts are `g000000007` (opted in) and `g000000008` (opted out),
both with game login password `fixture`. Each has a deliberately public,
synthetic 32-byte device key consisting of byte `0x42` repeated 32 times.
The HTTP checker uses disposable device ID `0123456789abcdef`; an emulator may
use its own disposable ID. State persists only during that container lifetime.

Existing endpoints are preserved:

- `/cgb/download?name=/A4/CGB-BMVJ/h0000.cgb`: historical static menu.
- `/cgb/download?name=/A4/CGB-BMVJ/RomList.cgb`: GB00-authenticated catalog.
- `/cgb/download?name=/A4/CGB-BMVJ/0000.G001.cgb`: fixture body, opt-in required.
- `/api/adapter/device-auth`: the existing signed device-auth endpoint.

The default body is `SYNTHETIC-BMVJ-HTTP-BODY` + NUL + the raw fixture. **Do not
attempt a game download with that body.** It exists for transport checks.

### Loading a verified external complete body

After Disassembly supplies the complete HTTP response body and its evidence,
mount a disposable fixture directory read-only at `/fixture` and add these
environment variables to the container command:

```sh
-v /absolute/disposable/fixture:/fixture:ro \
-e BMVJ_BODY=/fixture/body.cgb \
-e BMVJ_BODY_SHA256=<sha256-of-complete-body> \
-e BMVJ_METADATA=/fixture/metadata.json
```

The body is served verbatim. A matching hash verifies input integrity; it does
not validate the wrapper. Raw flash data must not be substituted for the body.
Example metadata (use Disassembly's actual ID, block count and game encoding):

```json
{
  "game_id": "G001",
  "blocks_needed": 1,
  "download_filename": "0000.G001.cgb",
  "price_yen": 0,
  "title_hex": "5041442054455354",
  "description_hex": "494e5055542054455354"
}
```

Optional catalog fields have the same names as `bmvj_custom_games`; title and
description are required hex bytes. Run the HTTP check with
`--body /absolute/disposable/fixture/body.cgb --filename 0000.G001.cgb`.

### Mobile GB Adapter / mGBA gate

The shipping libmobile build requests `device.auth.dion.ne.jp:80` at
`/api/adapter/device-auth`, before the game flow. The mGBA task verified that
its DNS configuration accepts an IPv4 address and explicit DNS port, while the
HTTP auth endpoint has no frontend override. For an isolated game test:

1. Use a disposable adapter config provisioned with the synthetic account/key
   above, and disposable ROM save/flash sidecars.
2. The mGBA tracer's local DNS must resolve only the exact game hostnames seen
   in its trace and `device.auth.dion.ne.jp` to loopback. Unknown names must
   fail instead of falling back to public DNS.
3. The local tracer must explicitly remap connect port 80 to harness port 8088,
   or the harness must bind loopback port 80 in an isolated environment. DNS
   changes an address, not an HTTP port. Record which method was used.
4. Confirm the signed device query and GB00 challenges in the local logs, then
   compare the fetched body hash. Attempt the natural download only after the
   wrapper gate is closed; verify written sidecar bytes/offset and launch.

This harness provides the HTTP side. DNS redirection, disposable adapter config
and connect-port remapping belong to the mGBA tracer; those have not been run
by the server task. This is not an end-to-end game download result.

### Complete Maker body checked on 2026-10-06

Disassembly supplied `/tmp/netdeget-http-pad/0000.G001.cgb` (1,013 bytes), with
SHA-256 `0c7e9f6b68878bde8d2610e3b553023f81305b4765a82303c2d992e5633d973a`,
and matching `metadata.json`. This is the Maker publication body, mode 5,
not the default synthetic transport marker. Its nine-byte header is
`00 00 05 EC 03 00 20 00 00`: L=0, reserved=0, mode=5, input length 1,004,
output length 8,192, check fields zero. These describe this specific artifact;
no generalized wrapper generator is implemented in REON.

Disassembly reports this exact L=0 body passing the synthetic SRAM entry through
the original producer/writer, 64 operations and zero mismatches after reopening
the sidecar. The local server independently checked its input hash and served
all 1,013 bytes unchanged through real GB00/device-auth HTTP flows. These tests
do not establish the natural game's HTTP acquisition or final checksum gate.
Preserve the supplied title/description encoding verbatim (including space
byte `20`); the Maker source owns reproduction of this disposable artifact.

To test this specific body with the harness, mount its directory at `/fixture`,
set `BMVJ_BODY=/fixture/0000.G001.cgb`, its SHA-256 above and
`BMVJ_METADATA=/fixture/metadata.json`. Verify with:

```sh
python3 web/tests/check_bmvj_local_http.py \
  --body /tmp/netdeget-http-pad/0000.G001.cgb --filename 0000.G001.cgb
```

For the coordinated run the server task leaves container `reon-bmvj-pad-http`
on `127.0.0.1:8088` temporarily. Read logs with `podman logs reon-bmvj-pad-http`
and `podman exec reon-bmvj-pad-http cat /var/log/reon/activity.log`.
After the mGBA tracer finishes, stop it with `podman stop reon-bmvj-pad-http`;
SQLite, sessions and logs disappear with that container.

The coordinated service subsequently switched to the independent WRAM bank 1
`D800` fixture at `/tmp/netdeget-http-pad-wram1/0000.G001.cgb`, 1,014 bytes,
SHA-256 `a8f6e181ddedf0f5d0b1b8e164d9e41edcddaadd14cf0c9f4730ede455560a24`,
with unchanged supplied metadata. Its mode 5 input length is 1,005 bytes; output
length stays 8,192. HTTP tests (including the captured SDK POST) pass against
this body. This is the currently coordinated version; the earlier body and its
logs are preserved separately in `/tmp/reon-bmvj-http-1013-evidence`.

The local harness also records response evidence in
`/var/log/reon/bmvj-http.jsonl`: method, path, status, numeric account, opt-in,
body length/hash and catalog count, without Authorization/request data.
Authenticated catalog bodies are saved as
`/tmp/reon-bmvj-local/catalog-{GET|POST}-{account}.bin` inside the container.
This instrumentation belongs only to the local test router.

### Natural download/write checkpoint, 2026-10-06

The coordinated mGBA run used real ROM joypad/menu flow, original Mobile GB
Adapter emulation, isolated DNS and an explicit connect80-to8088 remap.
The corrected 434-byte catalog displayed PAD TEST. The ROM naturally requested
`0000.G001.cgb` using GET401, GB00, GET200 and an empty POST200; both successful
responses contained exactly 1,014 bytes with SHA-256
`a8f6e181ddedf0f5d0b1b8e164d9e41edcddaadd14cf0c9f4730ede455560a24`.
Server logs independently confirm account 7, opt-in 1 and both body hashes;
the captured snapshot is `/tmp/reon-bmvj-natural-stage114-evidence`.

mGBA reports the natural download-complete/BOX2/storage-complete sequence and
8,192 bytes persisted at flash offset 0, equal to the D800 payload SHA-256
`0e42875ef2569905d056f895ab5d6998e4f17709875dd27f13cbd9b20c2158b0`.
This run did not force CPU entry or inject the network response body. Those
host/write assertions belong to the mGBA trace; the server independently
verified its HTTP responses. Launch, all inputs, exit and reopen tests continue
in that chat. The SQLite-backed harness does not validate MySQL migration or
production/hardware behavior. Preserve logs before stopping its container.

The same-core natural run subsequently passed BOX2 PAD TEST launch, all eight
inputs (press/release/mask and each counter once), Start+Select exit and BOX1
relaunch with counters reset, as reported by mGBA stages 126/142/144/180.
Fresh-core reopen was still in progress at this checkpoint. Server HTTP
response evidence is preserved in
`web/tests/fixtures/bmvj/natural-http-evidence.json`, with emulator assertions
explicitly attributed to the separate trace. Final logs/catalog snapshots are
in `/tmp/reon-bmvj-natural-final-evidence`. The coordinated local container
was stopped after capture; no local HTTP service remains from this test.

Fresh-core reopen subsequently passed in the mGBA task with persisted flash
unchanged (`/tmp/mgba-downloaded-reopen-7fc8_kt9/reopen.log`). The evidence JSON
records that result with its source. An additional short HTTP run was requested
to validate the portable runner being saved in the mGBA repository.

The mGBA task preserves its portable natural-flow runner at
`tools/mbc6/run_netdeget_local.py` and `netdeget_local_trace.c` in its own
repository. Its CLI accepts the original host ROM, Linux shared build,
validated empty SRAM, erased flash sidecar and the exact 8 KiB D800 payload.
It verifies input and response hashes, creates disposable saves, and owns the
isolated DNS/connect-port remap. Use that runner's documented fixture inputs;
this REON repository supplies only the HTTP harness.

The portable runner's final post-commit repeat (`mGBA 358230c82`) also passed
natural download/write, eight inputs, exit/relaunch and fresh-core reopen,
per `/tmp/mgba-netdeget-local-scaagyaa/run.log` and `reopened/run.log`.
Server-side GET/POST catalog/body hashes were independently checked again;
final snapshots are `/tmp/reon-bmvj-portable-final-evidence`. The HTTP container
was stopped after this final capture. mGBA reports its temporary DNS8053 was
closed by the runner's `finally` block. No production deployment occurred.

### C / GBDK fixture handoff

Disassembly supplied the C PAD TEST built with GBDK 4.5.0 at
`/tmp/net-de-get-maker-sdk/build/c-pad`. Its original `game.json` uses Maker
names; normalize them into a separate harness metadata file without editing
the original or re-encoding strings:

```python
metadata = {
    "game_id": maker["gameId"],
    "blocks_needed": maker["blocks"],
    "category_icon": maker["genre"],
    "minigame_type": maker["category"],
    "title_hex": maker["titleHex"],
    "description_hex": maker["descriptionHex"],
    "download_filename": maker["downloadFilename"],
    "price_yen": maker["price"],
}
```

The mapping is backed by Maker `tools/package.py`: `category` comes from raw
payload byte 6 (minigame type), `genre` from byte 7 (catalog icon).
The isolated server directory `/tmp/reon-bmvj-c-pad-fixture` contains the
unchanged body, original `maker-game.json`, and normalized `metadata.json`.
No host ROM, payload or user configuration is added to this repository.

- Payload 8,192 bytes: `ab49fffb02e1b918d442a876508ed83c75ffc32fbbfa482cb8a3b9e46d70381c`.
- Complete body 1,114 bytes: `f46337ae8627482742511c5d508aaa0ac66930de93c9fe2814fd0c00a02324ba`.
- Catalog 437 bytes/count 5: `d5323f206466632a447ceb68168175af5d8c6de66e441c9a58f7868c65e679e6`.

The server independently verified block=1, icon=6, type=1 and title bytes
`C PAD TEST` in the serialized record. HTTP GB00/device-auth/opt-in checks and
exact GET/POST catalog/body equality passed; payload POST also rejects opt-out.
The container for this coordinated fixture is `reon-bmvj-c-pad-http` on local
8088. Natural C-example download/write/gameplay/fresh-core evidence belongs to
the mGBA runner using its SHA-256 CLI options; HTTP success alone does not close
those gates. Preserve local logs and stop the container after that round.

The coordinated C/GBDK round completed: mGBA reports natural download/write,
eight input counters, exit/relaunch and fresh-core reopen with unchanged flash
in `/tmp/mgba-netdeget-local-e964hi6b/{run.log,reopened/run.log}`. The server
independently checked the final authenticated GET/POST catalog and body hashes
above. Logs are archived at `/tmp/reon-bmvj-c-pad-final-evidence`;
`reon-bmvj-c-pad-http` was stopped and its absence confirmed.
These local emulator results do not validate production MySQL or hardware.

## Content publication

The initial free opt-in content is C PAD TEST, built from Maker commit
`727b06b607adea93e5fbe10b6890b45739be1070`. Copyright (c) 2026 zenaror,
MIT; the complete notice is in `NET_DE_GET_C_PAD_LICENSE.txt`. Only original
Maker additions and their generated example program/font are licensed this way.

Run `php maint/import_bmvj_game.php /path/game.json` to import an inactive
entry, or append `--activate` for explicitly approved publication. It checks
complete body length/SHA-256, game-encoded metadata, baseline ID collision and
stored database bytes. Existing IDs/filenames are refused. This first importer
accepts free content only; paid SDK GET/POST billing needs separate lifecycle
validation before paid publication. Deactivate with an operator-approved update
to `bmvj_custom_games.is_active`; account opt-in stays independent.

## Production deployment — 2026-10-06

At 18:53 Brasília / 21:53 UTC the MySQL migration
`20261006120000` and implementation files were deployed by restricted copy.
All previously existing code files matched `feature/full_server` before
replacement. PHP 8.5.4 lint and installed/staged byte comparisons passed.
Database backup: `/var/backups/reon/mysql-reon_db-20261006-215156.sql.gz`.
File backup: `/var/backups/reon/netdeget-20261006-215327/files-before.tar.gz`;
`new-paths.json` records files to remove if reverting the code.

C PAD TEST G001 was imported active and free with the exact body SHA above.
Existing account preferences stayed off. A temporary synthetic test account
first exercised GB00, unchanged opt-out baseline and GET/POST payload404;
after its explicit opt-in, GET/POST catalog437B/count5 and body1114B matched
the validated hashes byte-for-byte over the public endpoint. Duplicate import
was refused. nginx/PHP-FPM/MySQL stayed active; PHP error log was empty.
Natural emulator acquisition from the real server is being coordinated;
credentials are kept only in protected temporary files and must be removed
along with the synthetic account after that round. No hardware claim.

To revert publication, deactivate G001 first. Restore the previous files and
remove new code paths using the saved manifest if code rollback is necessary.
The additive schema can remain while old code is restored; do not roll back
other migrations or restore a whole database over later user activity.
