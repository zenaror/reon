# Net de Get server integration

## Scope

REON serves the game's existing `A4/CGB-BMVJ` resources through the normal CGB
download front controller. Custom games are account opt-in content: the
official catalog remains present for every authenticated account, and active
REON entries are appended only when `sys_users.custom_bmvj_opt_in` is enabled.
The download endpoint checks the same opt-in before returning a custom payload.

## Binary contract used by this implementation

The implementation follows Dan Docs' Net de Get section and the local copy at
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

The original game's behavior with the utility-auth challenge and this
personalized catalog still needs validation with a verified local test game.
Do not infer that the menu, catalog rendering, download, Flash programming, or
gameplay works solely from the serializer test.

## Validation status

PHP 8.5 lint and `web/tests/test_bmvj_catalog.php` passed offline. The unit
fixture covers baseline preservation, record encoding, duplicate IDs, session
selection, opt-in/out, eligible charging, and exact synthetic body pass-through.
`web/tests/check_bmvj_local_http.py` additionally passed against the local HTTP
harness: real GB00 challenge/response, invalid password, opted-in catalog,
opted-out baseline/download refusal, static menu bytes, and exact response body.
It also checks the real device-auth handler's signed query, authorize/deauthorize,
stale counter rejection and invalid signature rejection.

| Contract | Evidence and remaining limit |
| --- | --- |
| Catalog fields/offsets, `Gxyz`, price filename | Dan Docs plus offline serializer/HTTP tests; the natural game rendering of this personalized catalog is pending. |
| GB00 and per-account selection | Existing REON auth code, exercised through HTTP; original Net de Get's utility-auth behavior remains pending. |
| Stored body transport | HTTP bytes equal the stored synthetic body; the server adds no wrapper. |
| Device-auth query/signatures | Existing `DeviceAuthUtil` and endpoint exercised with disposable fixture accounts; SQLite adapter does not validate MySQL migrations. |
| Host recognition/launch | Disassembly/mGBA reported natural NASU listing/launch in BOX1/BOX2 using preloaded local flash/SRAM fixtures. This bypasses download. |
| Host writer/persistence | Disassembly reported original caller/writer persisting artificial 4 KiB to a disposable sidecar. New synthetic traces also feed a wrapper in SRAM through the ROM producer and writer: 1,250-byte PAD TEST in modes 0/5, 32 operations, zero mismatches. Mode 0 reader skips 256 bytes per block of at most 512; mode 5 Maker compression passed without those extras. Acquisition HTTP/menu and final checksum validation are still bypassed; this is not an HTTP framing contract. |
| Download wrapper and real buffer producer | Still unconfirmed. Header length/size, chunk/footer, padding, and association with the received HTTP body await Disassembly evidence. |

The checked-in 168-byte PAD TEST is an older raw flash fixture. The separately
reported 1,250-byte visually passing PAD TEST has not replaced it here. Neither
raw image is a verified complete HTTP body. No production deployment or natural
emulator download was used for these server checks.

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
