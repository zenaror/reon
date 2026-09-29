# Game Boy Wars 3 content

What the server serves to Game Boy Wars 3 (`CGB-BWWJ` / `CGB-BWWE`) and how to
manage it. Routes are handled in `web/cgb/gbwars/`; the format helpers are in
`web/classes/GameboyWars3Util.php`.

## Maps

- **Ids.** `0000`-`1999` are official (today 1001, 1002, 1003, 1004, 1006);
  `2000`-`9999` are REON maps. An id is never typed: publishing takes the next
  free one, and the same number is written inside the map file, like the
  official maps. The first custom map is `2000`.
- **Served as.** The game asks for `0.map_menu.txt` (the `0.` prefix makes it an
  authenticated request, which is how the server knows the account) and then
  `map/N/map_NNNN.cgb`. `map_data` is stored **without** the 2-byte header
  (`20 00` Japanese, `21 00` English), which is added on serve.
- **Who sees custom maps.** Only accounts that ticked *Game Boy Wars 3: Map
  Preferences* on their account page (`sys_users.custom_gbwars_opt_in`, off by
  default). Official maps are always listed. The opt-in filters the **menu**;
  the map file request carries no cost prefix, so it cannot tell who asks
  (the game only requests numbers its own menu listed).
- **File layout** (with header): category text at `0x10` (9 bytes), map number
  at `0x1E` (BCD), name at `0x20` (8 bytes), gold `0x28`/`0x29` (x1000),
  materials `0x2A`/`0x2B` (x10), width `0x2C`, height `0x2D` (20-50), then
  `width*height` terrain bytes, then units (`x, y, id`), then `0xFF`. Checksums
  cover from the name to the terminator. `GameboyWars3Util::createMapData()`
  builds it and was checked byte for byte against four of the five official
  maps.
- **Known oddity:** official map 1006 stores its checksum as the two's
  complement of the sum, unlike the others, so `validateMap()` rejects it. It is
  served as it came from upstream; whether the game checks that byte is only
  answered by a console.

## Admin: `/admin/gbwars_maps.php`

Three tabs:

1. **Maps**: every map with price, English name/category, downloads, and
   activate/deactivate. *Copy to creator* opens a copy in the creator (an
   official map is never overwritten; the copy publishes as a new REON map).
2. **Upload a file**: a finished full map file (header included); it must pass
   the checksum.
3. **Map creator**: drafts (`bww_map_drafts`). *New map* / *Open creator* open
   `/admin/gbwars_map_editor.php` in a new tab.

**The creator** paints terrain (ids `0x01`-`0x2A`, drawn from
`images/gbwars/terrain_16x16.png` and `water.png`), places units (ids 2-103, even
= Red Star, odd = White Moon), fills, picks, undoes and redoes. The map's name
(up to 8 characters of the game's font: capitals, digits, kana) is also the
draft's name. It warns when a map does not have exactly one base of each side
(every official map has). *Save* stores the draft, *Download .cgb* builds the
game file, *Publish* inserts a new **inactive** map: activate it in the Maps tab.
Publishing again makes another new map and never overwrites one.

## Mercenary prices, mailbox

- `/admin/gbwars_mercs.php`: the five prices in `0.youhei_menu.txt`
  (`bww_mercenary_prices`; defaults 30, 50, 50, 50, 50).
- `/admin/gbwars_mbox.php`: the game's own "News" (its disassembly calls it
  MESSAGES): broadcast text in 16 fixed slots per region (`bww_messages`),
  re-served with a new serial number on every edit. The stored bytes are plain
  UTF-8/ASCII under a legacy header, matching what was already live; that
  encoding is not confirmed on a console.

## Not yet tested on hardware

The creator, the opt-in and everything published from them have only been
verified by script and browser against real data, never in a game.
