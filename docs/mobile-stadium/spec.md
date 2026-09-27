<!--
  PROVENANCE — read this before trusting anything below.

  This document was NOT written by the REON server side. It is the work of the
  "PKHeX Linux Port" session, read out of the pokecrystal-mobile disassemblies
  (eng/fra/ger/ita/spa) plus a byte comparison against the Japanese ROM. It was
  copied here on 2026-09-27 because it lived in a temporary scratchpad that
  disappears with that session, and losing it would mean redoing the work.

  Copied verbatim. Nothing in the body has been edited; this comment is the only
  addition. If you change the body, say so here.

    spec.md          md5 51214a7ee83a7dc9f9e3508147e72c25
    crystal_check.py md5 0fdafe4ff00404f8ba1605527ecbe3e2   (copied unmodified, alongside this file)

  What the REON server side verified INDEPENDENTLY, rather than taking on trust
  (2026-09-27):

    - The menu.cgb layout in section 1.3 reproduces our live file exactly. Built
      the parser from the spec alone and the predicted size came out 0x6F = 111
      bytes, the whole file, with URL length 82 at 0x1B-0x1C as a 16-bit LE.
    - The marker bytes of our menu are 00 00, not "P3" — confirmed byte by byte.
    - crystal_check.py, run against our production files, prints
      "payload: ACCEPTED -> copied to SRAM" then
      "stadium frame MISSING/INVALID - Stadium lists nothing".
    - The cost prefix claim matches our own code: web/cgb/auth.php:248 reads the
      digits before the first dot and addCostToAccount() charges them to
      sys_users.money_spent, and auth.php:12 only requires authentication when a
      cost exists.
    - REON distributes no ROMs or patches today, so the Italian/Spanish ROM bug
      in section 3.3 affects nothing we ship. The "Games and patches" section of
      web/pages/downloads.en.md is an unwritten stub.

  What is NOT verified, quoted from the document's own section 7: nothing here has
  run on an emulator or a console; only the FF x6 "always" schedule was traced end
  to end; and the ROMs REON actually distributes were not checked.

  Nothing in here has been implemented on the server. Serving Stadium data from
  the database is a request from the project owner, relayed through that session
  on 2026-09-27, and it is waiting for his word in the server session before any
  code is written.
-->

# MOBILE STADIUM download: what Crystal expects, and what "Compile data for REON" should produce

Status: this is a **static reading**. Sources were the pokecrystal-mobile disassemblies (eng/fra/ger/ita/spa, built,
with `.sym`), a byte comparison against the Japanese ROM (`baserom-jp.gb`), and the REON server sources. Nothing here
was run in an emulator or against a server. `crystal_check.py` in this folder is an offline model of the Crystal checks
in section 1. It reads files and writes nothing. Running it against the REON stubs and the real saves reproduced every
verdict in section 4.

Addresses are `bank:addr` from `pokecrystal-mobile-eng/pokecrystal.sym` (BXTE build). The `_eu` (BXTP) and `_au` (BXTU)
builds have the same addresses. Line numbers are in `pokecrystal-mobile-eng/`. JP addresses were found by matching
byte patterns in `baserom-jp.gb`.

---

## 0. Summary

- Crystal sends two HTTP GETs. First it fetches the hard-coded `…/01/CGB-BXT<r>/POKESTA/menu.cgb`, then the one URL that the
  selected menu entry names.
- `menu.cgb` is binary. It holds a count byte, then entries of `6 schedule bytes + 16-byte File ID + "P3" + LE sum + LE URL
  length + URL`, with no terminator.
- The payload must be **exactly 0xFFE bytes**, and its File ID at 0xFEA must equal the File ID in the menu entry.
  Crystal checks nothing else. Crystal then copies **0x1000** bytes into SRAM bank 7 at 0xB000, which is save offset
  0xF000. The last 2 of those bytes were never transferred: they are WRAM garbage, normally 00 00.
- Only one payload is downloaded per session: the first menu entry that is eligible. A three-battle distribution is
  **one** 0xFFE-byte block file.
- JP and all western builds run identical Crystal code, with the same sizes and offsets. Only the payload's contents
  differ (record stride 0x480 against 0x490, and the text encoding).
- **The Italian (BXTI) and Spanish (BXTS) builds have a bug**: they never parse `menu.cgb`. Details in §3.3.
- Neither current stub is usable. The JP one passes Crystal's checks but Stadium lists nothing from it. The "EN" one would
  fail Crystal's checks, and nothing fetches it anyway, because both menus point at the BXTJ file.

---

## 1. The download flow in Crystal (question 1)

### 1.1 Entry and state machine
- `MobileStudium` 45:7b3e (`mobile/mobile_45_stadium.asm:645`) is the "MOBILE STADIUM" option. Its jumptable at :697
  runs a YES/NO prompt and then `Function117bb6` 45:7c75 (:795).
- `Function117bb6` first calls `Function117c89` 45:7d48 (:904-932). That routine opens SRAM bank 7 and computes
  **`sum16(s7_b000[0x000..0xFFB])`** (0xFFC bytes, stored in wcd83/wcd84). It also copies the **held File ID**
  `s7_bfea` (bank 7 0xBFEA, i.e. block+0xFEA, 16 bytes) to wcd69.
- It then calls `Function118284` (`mobile/mobile_46.asm:301`). That routine drives the jumptable `Function1186f5` 46:473b
  (:883-918): adapter init, EEPROM read, login ID and e-mail, ISP login, **`HttpGetStadiumGSIndexFile`** 46:4dae (:1857),
  **`Function119451`** 46:5780 (menu parse), then `Function1195f8`/`Function119612` → `Function119629` 46:5958 →
  **`Function119648`** 46:5977 (payload GET), and finally logout.

### 1.2 The HTTP GETs and the receive buffer
- Menu URL (`StadiumDownloadURL` 46:4fc4, `mobile_46.asm:2080-2089`), one per build:
  `http://gameboy.datacenter.ne.jp/cgb/download?name=/01/CGB-BXT<r>/POKESTA/menu.cgb`, where `<r>` = J (JP ROM 46:4fbb),
  E (default build), P (`_CRYSTAL_EU`), U (`_CRYSTAL_AU`), F, D, I or S (the fra/ger/ita/spa repos). **The path is fixed
  in ROM.** It is not built from anything the player enters.
- Both GETs use `MOBILEAPI_15` = `Function110ddd` 44:4ddd (`lib/mobile/main.asm:2082`), with `de = w3_d000` and
  `bc = $1000` (menu: :1862-1864; payload: `Function119648`, :3459-3462).
- The library stores **the received length as a LE u16 in the first 2 bytes of the buffer, then the body**
  (`Function1125c7` 44:65c7, main.asm:5669: `wc827 = de`, `de += 2`, `bc -= 2`). **The largest body that fits is
  0x1000 − 2 = 0xFFE bytes.** If a body is longer, the game switches to a second buffer and sets `wcd89` bit 0
  (`MobileAdapterCommunication` 46:47d3, :954-1003). Every consumer then rejects it with error **D3**: the menu at
  `Function119451` :3138-3142, the payload at 45:7cb4 (:824-826).
- The library also copies the **HTTP `Date:` response header** into `wc708`. Function112fd5 44:6fd5 (main.asm:7245) matches
  `"date: "`. The menu's schedule is evaluated against that header, so all times are UTC.
- Authentication: the param block (`Function118b24` 46:4e16, :1919) passes the login ID and the adapter password. The
  library answers the server's `401 WWW-Authenticate: GB00` challenge by itself, so a cost-prefixed payload works with
  REON's `doAuth` (`web/cgb/auth.php`).

### 1.3 `menu.cgb` format (parsed by `Function119451` 46:5780 → `Function119471` 46:57a0, :3137-3332)
The file body is `w3_d002…` (`wd002` = the count, `wd003` = the first entry).

| Offset | Size | Field |
|---|---|---|
| 0x00 | 1 | **N = number of entries.** It must be ≥ 1: 0 is read as 256 entries of garbage (the loop runs `dec a` / `jp nz`, :3311-3313). |
| per entry +0x00 | 1 | first day (0 = Mon … 6 = Sun, FF = any) |
| +0x01 | 1 | last day |
| +0x02 | 1 | start hour (FF = any) |
| +0x03 | 1 | start minute (FF = any) |
| +0x04 | 1 | end hour |
| +0x05 | 1 | end minute |
| +0x06 | 16 | **File ID**. The payload must carry the same 16 bytes at 0xFEA. |
| +0x16 | 2 | `50 33` ("P3") enables the checksum comparison. Any other value disables it. |
| +0x18 | 2 | LE u16: expected `sum16(block[0x000..0xFFB])`, the same value as the payload's own 0xFFC-0xFFD |
| +0x1A | 2 | LE u16 URL length L. **L ≤ 0xA5** is required, otherwise error D8 (`Function1195c4` 46:58f3, :3379-3410). |
| +0x1C | L | URL, ASCII, **no terminator**. The game appends a 0 itself. |

Each entry is `0x1C + L` bytes. The whole menu must be ≤ 0xFFE bytes.

How the 6 schedule bytes are loaded (:3154-3166): byte0→`wc608[0]`, byte1→`[3]`, byte2→`[1]`, byte3→`[2]`, byte4→`[4]`,
byte5→`[5]`. That gives the triples start = (day0, h_start, m_start) and end = (day1, h_end, m_end). They are compared
with (weekday, hour, minute) taken from the Date header: `Function119694` 46:59c3 matches "Mon".."Sun" in
`Unknown_1196b8` (:3527; JP 46:5a50) and returns 7 when nothing matches, and `Function1196cd` 46:59fc reads the hour and
minute at `wc719`. The reading matches REON's own `reon-docs/CGB-BXTJ/POKESTA/menu.cgb.md`. **`FF FF FF FF FF FF` =
always** (confirmed by tracing: every comparison skips FF). The plugin should always write FF×6. It should expose a
weekly schedule only if one is ever needed, because custom windows were not exercised.

**Selection** (:3250-3332), entries in file order:
1. If the schedule does not match, skip the entry.
2. Otherwise set "something is running" (`wcd50 = 1`) and compare the entry's File ID with the **held** one (wcd69).
   - **Different** → select this entry, with jumptable $0F. The player sees "There is data you don't have! Read it?"
     (RoomMenu2 $11, `String_11a743` :5765 + $12 `String_11a755`). The English strings are loose translations; the JP
     strings mean what is written here.
   - **Same**, the entry has `P3`, and its sum ≠ `sum16` of the held block → select, with jumptable $10: "You read this data
     before, but it is gone or broken. Read it?" (`String_11a762`/`String_11a779`).
   - **Same** and (no `P3`, or the sum matches) → skip the entry and keep scanning.
3. The first selected entry wins. The rest of the menu is not read.
4. If nothing was selected: if some entry was running, the player sees "You only have the same data!" (`String_11a791`,
   code 0x0A, silent exit). Otherwise the game shows **error D8**, "no Stadium distribution found".

When an entry is selected, `Function1195c4` copies its URL to `wcc60` and **its File ID into wcd69**. wcd69 is what the
payload is checked against later.

**Cost prompt** (`Function119629` 46:5958 → `Function118e39` 46:5168, :2258-2295; `Function11a14b` 46:649b;
`Function11a1d6`/`Function11a1e6` :5131-5155). The game takes the last `/` component of the URL and reads up to 4 leading
digits before a `.`:
- no leading digit → no prompt (free);
- 1–3 digits then `.` → "This is a paid service." / "Cost: N <currency>", then YES/NO;
- 4 digits, or a name that starts with `.` → **error D3** before the download.

This agrees with the server's `getCost()` (auth.php:207-214): the numeric part before the first `.` is the cost.

### 1.4 The payload and what is checked (`Function117bb6` 45:7c75, `mobile_45_stadium.asm:819-870`)
After the GET into `w3_d000`, the checks are:
1. `wcd89` bit 0 clear, i.e. the body was not larger than 0xFFE, else D3;
2. **`w3_d000 == $FE` and `w3_d001 == $0F`: the received length is exactly 0x0FFE**, else D3;
3. **`w3_dfec[0..15]` == wcd69**, i.e. payload[0xFEA..0xFF9] equals the File ID of the selected menu entry, else D3.

Nothing else is verified. The marker and sum at 0xFFA-0xFFD are **not** checked by Crystal, and neither are the header or
the records. On success the game copies **0x1000 bytes from `w3_d002` to `s7_b000`** (`ld bc, $1000`, :863-866; JP
45:7cf0), shows `MobileStadiumSuccessText`, and exits. Crystal never interprets the records: the only bank 7 symbols are
`s7_a001` (the friend record), `s7_b000` and `s7_bfea` (`ram/sram.asm:392-400`).

---

## 2. 0xFFE or 0x1000? (question 2)

- **Transferred: exactly 0xFFE bytes**, block offsets 0x000-0xFFD. That size is the buffer (0x1000) minus the length
  prefix, and the check at 45:7cb4 demands it exactly.
- **Written to SRAM: 0x1000 bytes.** Offsets 0xFFE-0xFFF are copied from `w3_e000`/`w3_e001`. Those are echo RAM of
  `C000-C001` (`wStackBottom`), not data from the server; both real saves have 00 00 there. No sum covers them (Crystal
  sums 0x000-0xFFB, and so does Stadium).
- Computed by the game: only its own `sum16(0x000..0xFFB)` of the **stored** block (`Function117c89`), used for the
  re-download test in §1.3. The payload's frame (`P3` at 0xFFA, LE sum of 0x000-0xFFB at 0xFFC) must be written by the
  server/plugin, because **Stadium** requires it. The JP Stadium lists nothing from a block without a valid frame
  (newreplay §1.2), and REON's western build drops such a block.

So the payload file = the 0x1000-byte block truncated to 0xFFE, and the menu's `P3`+sum = payload[0xFFA..0xFFD].

---

## 3. Japanese against western, and the European builds (question 3)

### 3.1 Crystal code: identical
JP ROM matches, with WRAM addresses shifted by −0xC (wcd7d/wcd5d/wcd77 instead of wcd89/wcd69/wcd83):

| Routine | EN | JP |
|---|---|---|
| Function117bb6 (checks + copy) | 45:7c75 | 45:7c71 (length check 45:7cb0, copy 45:7cf0) |
| Function117c89 (held sum/File ID) | 45:7d48 | 45:7d44 |
| StadiumDownloadURL | 46:4fc4 | 46:4fbb (`…/CGB-BXTJ/POKESTA/menu.cgb`) |
| Function118e39 (cost) | 46:5168 | 46:515f |
| Function119451 / 119471 (menu) | 46:5780 / 57a0 | 46:57e9 / 5809 |
| Function1195c4 (URL ≤ 0xA5) | 46:58f3 | 46:595c |
| Function11a14b (price prompt) | 46:649b | 46:667c |

The two copies of `Function117bb6..117c89` differ only in addresses (0x10F bytes compared). The parser's bytes are the same
apart from WRAM addresses. So, in all regions: 0xFFE payload, File ID at 0xFEA, the same menu format, and the same URL
limit.

### 3.2 Payload contents: differ by region (Crystal does not care, Stadium does)
| | JP (BXTJ) | Western (all 7 codes) |
|---|---|---|
| replay record stride | 0x480 at 0x004/0x484/0x904 | 0x490 at 0x004/0x494/0x924 |
| trainer side / side header | 0x1D4 / 32 | 0x1DC / 40 |
| rule records (5×0x48) | 0xD84-0xEEB | 0xDB4-0xF1B |
| message of the day | 0xEEC-0xFE0 | 0xF1C-0xFE0 |
| titles / display names | EUC-JP | ASCII |
| nicknames / OT in entries | JP Gen 2 charset | western Gen 2 charset |
| 0x000/0x001 counts, 0xFE1 flags, 0xFEA File ID, 0xFFA frame | same | same |

The western Stadium is REON's single English-only build (see the plugin README). BXTE, BXTP and BXTU are one source tree
built with 3 defines, and fra/ger/ita/spa are separate repositories. All seven load the same western record format. **One
western payload serves all seven codes.** Only the URL in each code's menu differs.

### 3.3 Bug in the Italian and Spanish builds (BXTI, BXTS)
In `pokecrystal-mobile-ita`/`-spa` (commit ef838fe, and the same in the copies under
`…/Pokemon Crystal BR/comparativo/`), the menu parser `Function119471` was moved to bank 7E (`mobile/mobile_46_2.asm:760`;
sym 7e:4a48 ita, 7e:4a47 spa), but nothing calls it. `Function119451` (46:5176) ends with `ld a,[wd002] / ld hl,wd003`
and **falls through into `Function1195f8`**. ROM bytes at 46:5176:
`… fa 02 d0 21 03 d0 3e 11 ea 3c cd …` (the correct builds have `… fa 02 d0 21 03 d0 f5 2a ea 08 c6 …`). fra and ger
are fine (parser at 46:57a0).

What follows in the bugged builds:
- The menu is never read.
- The game always shows the "data you don't have" prompt.
- `wcc60` still holds the **menu URL**, so the second GET fetches `menu.cgb` again. The name "menu" has no digits, so
  there is no cost and no auth.
- That second body must be 0xFFE bytes, and its 0xFEA must equal the File ID **already in the save**, because
  `Function1195c4` never overwrote wcd69.

A normal 111-byte `menu.cgb` therefore always ends in **D3** on BXTI/BXTS. The fix belongs in the ROMs (call
`Function119471` from `Function119451`). A server-side stopgap would be to serve the 0xFFE payload *as* `menu.cgb`, with
File ID 00×16. That works only for saves whose 0xBFEA area is zero: a fresh 0x00-initialised save, or a block made by
the plugin. It could never carry a cost prefix, and every distribution would have to keep the zero ID. I would not rely
on it. This analysis is static only.

---

## 4. The two stubs, decoded (question 4)

**`CGB-BXTJ/POKESTA/menu.cgb` = `CGB-BXTE/POKESTA/menu.cgb`** (111 bytes, identical, md5 7367e479…):
`01` N=1 · `FF×6` always · File ID `01 00×15` · marker `00 00` (no P3) · sum `00 00` · URL length `52 00` = 82 ·
`http://gameboy.datacenter.ne.jp/cgb/download?name=/01/CGB-BXTJ/POKESTA/20.test.cgb`. 1+0x1C+82 = 111. The format is
valid. **Both regions fetch the BXTJ payload.** No `P3`, so after one download that File ID is never offered again ("You
only have the same data!"). The name `20.` means a "Cost: 20" prompt, auth required, and 20 charged. Git history: before
e1fb64c (Oct 2023) the BXTJ menu was CRLF-separated text naming `metadata.bin` and `POKESTA.cgb`. Crystal cannot parse
that format, and those files never existed.

**`CGB-BXTJ/POKESTA/20.test.cgb`** (0xFFE, 149 non-zero bytes; d54f625, Dec 2022):
- 0x000-0x172 is not a Stadium block. It is a Crystal **friend record** (the bank 7 0xE001 kind): name `d8 8c 50 50 50`,
  TID `01 37` = 311 (the same trainer as the JP save's friend record), then nicknames, OTs, party, and `P3` at 0x16F.
  Its stored sum `355E` does not match the bytes.
- 0x7FE = `03`, 0xFEA = `01` (File ID matching the menu), 0xFE1 = 0, 0xFFA-0xFFD = `00 00 00 00`, so there is no frame.
- **Crystal accepts it** (0xFFE bytes, File ID = menu). It writes it over the player's block.
- **Stadium lists nothing**: header counts 0xD8/0x8C and no `P3` frame. JP Stadium stores 0/0 without an error. REON's
  western build drops the block. It is harmless, apart from wiping whatever download the player had.

**`CGB-BXTE/POKESTA/20.test.cgb`** (0x472 bytes; 86fd9f5, Jan 2023) is **never requested**, because the BXTE menu points to
BXTJ. Its content:
- `01 05 00 00` (1 replay, 5 rules), then **four extra zero bytes**.
- Then **one Japanese-layout replay record** starting at 0x008 instead of 0x004: in-game name `a5 86 80 50 00 00`,
  `40 01`, display name EUC-JP 「なかむら\nあきら」, TID `49 0e`, party at 0x28. The first Pokémon is the level 55 Tauros
  with 209,031 exp, the same battle as the first one in the real JP save.
- It ends just after the title 「ぜんこくけっしょう」 at 0x460, i.e. record+0x458, the JP title field.
- The record trailer is cut off, and so are records 2-3, the rules, the message of the day, the File ID and the frame.

0x472 = 8 + 0x46A: someone cut a JP record right after its title text. If a menu did point at it, Crystal would reject it
with **D3** twice over (length ≠ 0xFFE, no File ID). Even padded, neither Stadium would read it: the layout is JP, shifted
by 4 bytes, with no trailer. It is not an English payload.

For comparison, the two real saves (`_saves/CrystalJP.sav`, `pokecrystal.sav`, read only) hold complete blocks from a
test distribution by "OtherLiz":
- header `03 05 00 00`;
- File ID in ASCII: `"20260802TestFile"` (JP) and `"20260802WestFile"` (western);
- Delibird flags 0x03;
- a message of the day with markup tags (`<FONT LOAD 24>…`);
- valid frames (sum 05CD, 1070), with 0xFFE-0xFFF = 00 00.

Re-serving the JP save's own block with a matching menu passes `crystal_check.py` ("accepted"). Against the save that
already holds it, the model returns "You only have the same data!", as expected.

---

## 5. What the plugin should generate (question 5)

### 5.1 Inputs
- **Source block.** Take the save's download block (bank 7 0xB000, save 0xF000, 0x1000 bytes) after edits, and require the
  plugin's own block check to pass: `P3` records at the front, counts equal to their number, record and rule sums valid.
  A JP save compiles for BXTJ only. A western save compiles for BXTE, BXTP, BXTU, BXTD, BXTF, BXTI and BXTS. Refuse the
  other region (the stride test the plugin already uses).
- **File ID** (16 bytes). It must differ from every File ID served before and must not be 00×16, which is what a
  plugin-created block holds. Suggested form, following the precedent in the saves: ASCII `YYYYMMDD` + an 8-char tag,
  e.g. `20261005REONWk41`. Changing the File ID is what makes clients download again.
- **Message of the day.** Keep it or replace it. It fits in 0xEEC-0xFE0 JP (0xF5 bytes) or 0xF1C-0xFE0 western (0xC5 bytes),
  including the 00 terminator.
- **Delibird flags** at 0xFE1 (0x01 = GBA replaces the Game Boy, 0x02 = GameCube replaces the N64). Default 0. Warn that
  Stadium applies them permanently.
- **Payload base name.** It must not start with a digit or a `.`. Suggestion: `stadium-<tag>.cgb`.
- **Cost prefix** (owner's decision, see 5.4): *none* | `0` | `N` with 1 ≤ N ≤ 999.
- **Re-download on mismatch:** on (write `P3` + sum in the menu, recommended) or off (`00 00 00 00`).

### 5.2 Payload bytes (0xFFE) — `payload = block[0x000..0xFFD]` with:
| Offset | Value |
|---|---|
| 0x000 / 0x001 | active replay count (≤ 3) / active rule count (≤ 5), matching the `P3` records |
| 0x002-0x003 | 00 00 |
| 0x004 + i·S (S = 0x480 JP / 0x490 W) | replay i. Record trailer: `P3` (or `XX` for an empty slot) at +S−4, LE sum at +S−2 (JP Stadium: sum over 0x47E bytes, marker included) |
| 0x004 + 3S (0xD84 / 0xDB4) | 5 rule records × 0x48: trailer at +0x44, LE sum of +0x00..+0x45 |
| after the rules … 0xFE0 | message of the day, 00-terminated, zero-padded |
| 0xFE1 | Delibird flags |
| 0xFE2-0xFE9 | 00 |
| 0xFEA-0xFF9 | File ID |
| 0xFFA-0xFFB | `50 33` |
| 0xFFC-0xFFD | LE `sum16(payload[0x000..0xFFB])`, computed **last** |

The file length is exactly 4094 bytes; do not write 0xFFE-0xFFF. The western payload bytes are identical for all seven
western codes.

### 5.3 `menu.cgb` bytes (one entry per region directory)
```
01                                   N = 1
FF FF FF FF FF FF                    always
<File ID, 16 bytes>                  = payload[0xFEA..0xFF9]
50 33 <sum lo> <sum hi>              = payload[0xFFA..0xFFD]   (or 00 00 00 00 to never re-offer)
<L lo> <L hi>                        L = len(URL) (≤ 0xA5)
<URL, ASCII, no terminator>          http://gameboy.datacenter.ne.jp/cgb/download?name=/01/CGB-<code>/POKESTA/<[cost.]name>
```
Size = 0x1D + L. Keep the host and path form of the ROM's own URLs (`gameboy.datacenter.ne.jp/cgb/download?name=`). The adapter's DNS maps
that host to REON, and the library treats `gameboy.datacenter.ne.jp/cgb/…` URLs specially (`Function110ddd` compares
against `HTTPDownloadURL`), so do not use another host. With `stadium-<tag>.cgb` and a short tag, L is about 85-95 bytes.

**Several payloads in one menu?** Yes, up to 255 entries in ≤ 0xFFE bytes. But Crystal downloads **only the first eligible
entry** per session. A list therefore works only as a **schedule of disjoint time windows**, for example a different
block per weekday. Never list older distributions after the current one with overlapping windows: a player who holds the
current File ID skips it, gets offered the older one ("new data"), and next time the current one again, back and forth.
The old text format (two CRLF-separated URLs) is not something Crystal can parse. **A three-battle distribution is one
0xFFE file**: the block has exactly 3 replay slots and 5 rule slots. The plugin should emit one entry.

### 5.4 Cost prefix: an explicit parameter
| Prefix | Server (`auth.php` `getCost`/`doAuth`) | Crystal |
|---|---|---|
| none (`stadium-x.cgb`) | free, **no authentication**. Anyone can fetch it, and it carries trainer names and teams | no price prompt |
| `0.` (`0.stadium-x.cgb`) | **authentication required**, nothing charged (`addCostToAccount` returns on 0) | shows "This is a paid service. Cost: 0 <currency>" + YES/NO |
| `N.`, 1 ≤ N ≤ 999 | authentication required, N added to `money_spent` | "Cost: N <currency>" (western: the currency comes from the player's region, `mobile/currency_finder.asm`) |
| 4+ digits | would charge | **error D3**: never use |

The server side prefers a prefix so that replay data requires authentication. `0.` gives that without charging, at the
price of an odd "Cost: 0" prompt. The value is the owner's call. `menu.cgb` itself is always free, because its name is
fixed in ROM with no digits. Avoid names like `2x.cgb`: the server treats them as free, but Crystal shows "Cost: 2" plus a
stray glyph.

### 5.5 Output layout (under `web/cgb/download/01/`)
```
CGB-BXTJ/POKESTA/menu.cgb      + CGB-BXTJ/POKESTA/[cost.]stadium-<tag>.cgb   (JP payload)
CGB-BXTE/POKESTA/menu.cgb      + CGB-BXTE/POKESTA/[cost.]stadium-<tag>.cgb   (western payload)
CGB-BXTP, CGB-BXTU, CGB-BXTD, CGB-BXTF, CGB-BXTI, CGB-BXTS: the same, each menu pointing into its own directory
```
Only BXTJ and BXTE have `POKESTA/` today. The six others must be created. The current BXTE menu points at the BXTJ path and
must be replaced. Each menu can point to a single shared western file, but per-directory copies keep the server's
game-ID and region logic (`core.php` `extractGameIdFromPath`) and any per-game auth consistent. BXTI and BXTS will fail with
D3 until their ROMs are fixed (§3.3). Generate them anyway so the tree is complete, and flag them. The old
`20.test.cgb` files become unreferenced. The plugin only writes files into an output folder; publishing is the owner's.

### 5.6 Plugin-side self-check before writing
Run the §1.3/§1.4 logic on the output: length 0xFFE, File ID equal to the menu, URL ≤ 0xA5, N ≥ 1, menu ≤ 0xFFE, the name
rule, the frame, the counts. `crystal_check.py MENU PAYLOAD [SAVE]` does exactly this and can serve as the reference.

---

## 6. Testing end to end without production (question 6: what exists on this machine)

- **REON server locally**: `/home/rafael/REON_DEV/reon/docker-compose.yml` defines `db` (mysql), `migrate`, `web`
  (php-fpm), `nginx` (:80), `mail`, and **`dns`**: dnsmasq on host port **5354/udp** that maps
  `gameboy.datacenter.ne.jp` and `*.dion.ne.jp` to `EXTERNAL_IP` (`docker-dns-entry.sh`). The README gives
  `docker compose up -d` and `…/scripts/add_user.php` to create an account. Run it from a **copy** of the repo, with the
  generated files dropped under the copy's `web/cgb/download/01/…/POKESTA/`, so the production checkout stays untouched.
- **Emulators with an adapter**:
  - BGB at `/home/rafael/Downloads/emulador/bgb.exe` (Windows, runs under Wine), plus libmobile-bgb:
    `…/Reon Prod/_RELEASES/libmobile-bgb/mobile-linux`, source `…/MobileAdapterGB/libmobile-bgb`. Options: `--dns1`,
    `--dns2`, **`--dns_port`** (so `--dns1 127.0.0.1 --dns_port 5354` reaches the compose DNS), `--relay`.
  - The REON mGBA fork: `…/Reon Prod/_RELEASES/mGBA/Linux/mgba-qt.sh`, Tools > Mobile Adapter GB (DNS1 setting),
    `~/.config/mgba/config.ini` has `mobileAdapterEnabled=1`. It also performs **device auth** against
    `device.auth.dion.ne.jp` (`src/core/mobile-auth.c`), which the local DNS sends to the local server. Whether a local
    server satisfies that step was not checked. No DNS-port option was found in the fork's source, so the container may
    need to publish 53/udp for mGBA.
- **ROMs**: the built western Crystal ROMs in `scratchpad/eu/pokecrystal-mobile-*/pokecrystal*.gbc` and the JP baserom
  (read-only; copy both the ROM and the save).
- **The Stadium side**: the existing oracle, `pkhex-plugins/tools/oracle/start_oracle.sh` (RetroArch, Transfer Pak, JP
  ROMs by default; REON US/PAL Stadium ROMs work since the core ini was patched). After Crystal downloads, load the
  resulting `.sav` there and check that the three battles are listed and play.
- **Suggested loop**:
  1. Compile.
  2. Run `crystal_check.py`, which needs no network.
  3. Start the local compose copy, with an account from `add_user.php` if a prefix is used.
  4. Point an emulator at `127.0.0.1:5354` and run MOBILE STADIUM on a copy of a save. Check the prompts: new data / price.
  5. Diff the `.sav` bank 7 0xF000 area against the payload. It should be identical for 0xFFE bytes.
  6. Run MOBILE STADIUM again, and expect "You only have the same data!".
  7. Edit one battle in the plugin, run it again, and expect the "gone or broken" re-offer.
  8. Load the result into the Stadium oracle.

---

## 7. Not verified / open
- Custom schedule windows: only FF×6 was traced end to end.
- The ita/spa diagnosis comes from the source and ROM bytes of the local clones. The ROMs REON actually distributes were
  not checked. Check them by looking at 46:5176 for `3e 11` right after `21 03 d0`.
- Whether a real HTTP error (401 loop, 404) shows a specific code on screen was not traced.
- Nothing here has run on an emulator or console yet.
