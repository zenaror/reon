# How to ship a new Mobile Stadium distribution

This is the "how to use it" guide. For the byte format itself and the
reasoning behind each rule, see `spec.md` -- this guide doesn't repeat that,
just the end-to-end path.

## The path, in three steps

1. **Generate the `<slug>.bin` + `<slug>.json` pair** -- from an
   already-tested save, with `make_distribution.py` (command line) or,
   where available, the "Compile data for REON" button in the PKHeX plugin
   on the emulator. Both produce exactly the same pair; the button is the
   UI version of the same script.
2. **Import** with `maint/import_stadium_distribution.php`. Validates the
   format and writes to the database -- **never activates on its own**.
3. **Activate** at `/admin/stadium.php`, per region. This is where, and
   only where, the content starts being served to any console that asks
   for it.

No step skips the previous one: the importer rejects a malformed `.bin`
before it reaches the database, and a row only shows up in the panel after
it's been imported.

## Step 1 -- generate the pair

```
python3 docs/mobile-stadium/make_distribution.py <save.sav> <output-directory> \
    --slug <slug> \
    --file-id <16 characters> \
    [--region j|e|p|u|d|f|i|s] \
    [--message "<message of the day, with Stadium markup>"] \
    [--flags <Delibird flags byte>] \
    [--cost null|0|N] \
    [--battles N] \
    [--title "<free-form description, panel only>"]
```

- **`<save.sav>`** needs to already have the download block assembled at
  `0xF000` (0x1000 bytes) -- i.e. a save someone has already tested on the
  emulator against Mobile Stadium, with the battles to be distributed
  already recorded in it. The script doesn't assemble any battle; it only
  swaps the fields of an already-ready distribution.
- **`--slug`** becomes the name of the served file (no extension, no cost
  prefix) and the row's identifier in the database. `[a-z0-9-]`, up to 40
  characters.
- **`--file-id`** must be **new** -- 16 ASCII characters, unique per
  region. This is how the game decides "this is different from what I
  already have" and offers the download. Reusing a value makes the game
  say "you already have the same data again" and it never downloads.
- **`--message`**, if omitted, keeps whatever message was already recorded
  in the save (normally whoever generated the test save's -- **probably
  not what you want to distribute**). Pass your own, with Stadium markup
  (`<FONT LOAD nn>`, `<LINE nn>`, etc. -- see `spec.md` for the syntax).
- **`--flags`**, the Delibird flags byte. **Be careful with this one**:
  bits `0x01` (Game Boy -> Game Boy Advance) and `0x02` (Nintendo 64 ->
  GameCube) switch the player's platform **permanently**, and Stadium
  doesn't undo it. Unless that's clearly intended, use `0`.
- **`--cost`** decides who can download it, and the recommended choice is
  `0`: requires an authenticated session and charges the player nothing
  (see the table in `spec.md` §5.4). No prefix (`null`) opens the download
  up **with no authentication at all** -- not the default for content that
  carries a trainer name and team.
- **`--region`** is the letter the server uses (`j`/`e`/`p`/`u`/`d`/`f`/`i`/`s`),
  not the 4-letter code. For the seven western regions the `.bin` content
  is byte-identical across them -- generate one per region anyway, because
  each needs its own pair in the import format.

The result is two files in `<output-directory>/`: `<slug>.bin` (the
block, exactly 0xFFE bytes) and `<slug>.json` (the metadata). Don't edit
the `.bin` by hand -- any changed byte invalidates the checksum the script
already computed.

## Step 2 -- import

On the server (that's where the database lives):

```
php maint/import_stadium_distribution.php <path/to/slug.json>
```

or, for several pairs at once (for example, one region per subdirectory):

```
php maint/import_stadium_distribution.php --dir <directory>
```

Before writing, the importer checks: the exact size (0xFFE bytes), the
`.json`'s File ID matching what's recorded inside the `.bin` itself, and
the `P3`+checksum frame at the end of the block -- that last check is the
one missing from the two old upstream files, and it's what makes Stadium
list the distribution instead of showing an empty list. If any of these
fail, it refuses with a message saying which one, and writes nothing.

The row is born **inactive**. Re-importing the same File ID is safe -- the
importer rejects the duplicate with a clear message, without breaking
anything.

## Step 3 -- activate

At `/admin/stadium.php?region=<letter>`, the distribution shows up in the
list with an **Activate** button. Only after that click does it enter the
`menu.cgb` the game downloads and start actually being offered.

Two things to know before clicking:

- **Only the first eligible entry per session gets downloaded.** Activating
  more than one distribution in the same region only makes sense as
  non-overlapping time windows (see `spec.md` §5.3) -- never leave an old
  distribution active "behind" a new one with the same window: whoever
  already has the new one gets the old one offered again, alternating.
- **Deactivating doesn't delete the row or the file.** A console that has
  already downloaded that block keeps it -- deactivating only stops *new*
  downloads. This is the same kind of limit that exists on the player's
  side: once the Game Boy has the block, the server can no longer reach
  that copy.

## Verify before trusting it

`crystal_check.py` models the same checks Crystal and Stadium perform,
without needing an emulator or a console:

```
python3 docs/mobile-stadium/crystal_check.py <menu.cgb> <payload.bin> [save.sav]
```

It reports whether the payload would be accepted, whether the frame is
valid (i.e. whether Stadium will list the distribution), and the price
that would show on screen. It's the same tool used to validate every
payload in this project before activating it in production -- worth
running against your own pair before step 2, not just after.

## Provenance

`spec.md`, `crystal_check.py`, and this same `make_distribution.py` came
from the "PKHeX Linux Port" session, read off the Crystal disassembly --
they are not server-side work. Each file carries its own header saying so.
This README is the exception: it was written on the server side, to
document the flow that the three files above already allowed, but that
none of them explained on its own.
