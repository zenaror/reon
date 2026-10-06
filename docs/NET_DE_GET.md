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

`web/tests/test_bmvj_catalog.php` covers baseline preservation, custom record
encoding, opt-out behavior, and duplicate IDs. It has not run in this
environment because PHP is unavailable. No production server, deployment, or
emulator download was used for this change. The separate Net de Get Disassembly
task owns creation of a compatible input-test game; until its payload is
verified, route-level work can only be checked with local fixtures.
