# rom-patches — game patches built from the repositories

`reon-patch-build` builds the games from their public source repositories
and publishes a **BPS patch** for each one, made against the *official* ROM.
It never publishes a ROM: the only files that leave the machine are the `.bps`
files and `manifest.json`. The Downloads page lists whatever the manifest says.

| file | what it is |
| --- | --- |
| `reon-patch-build` | the tool (Python 3, standard library only) |
| `bps.py` | BPS create/apply. The applier is a separate code path from the creator, on purpose: it is what proves a patch before it is published |
| `games.json` | the catalog: which repositories, which rgbds, which official ROM (by SHA-1) each patch is made against |

Installed on the server by `setup-script/6-setup-rom-patches.sh` into
`/usr/local/lib/reon-patch-build` (owned by root, so the web user cannot edit
what the build user runs). After changing anything here, run that script
again.

## What one run does

For every game in the catalog:

1. `git fetch` the repository into `/var/lib/reon-patches/src/<id>`.
2. If the commit, the catalog entry and the published files are all what they
   were last time, stop: nothing changed (a run with nothing to do takes
   seconds).
3. Build with the rgbds version the game needs (`make`).
4. For each official base ROM: make the patch, **apply it back** to that ROM
   and require the result to equal the build byte for byte, then pass the
   publish guard (below), then write it into `public/` (atomic rename).
5. Write `public/manifest.json`.

A game that fails keeps its last good patch online and the run exits non-zero
(systemd shows the unit as failed). A game whose official ROM is not in the
private directory is skipped with a warning; that is a to-do, not an error.

### The publish guard

Last step before anything reaches `public/`, re-derived from the bytes: plain
`.bps` name, `BPS1` header, applies to its base and reproduces the build,
smaller than half the ROM (`max_patch_ratio` per game overrides it) so a patch
that is really carrying the game is refused, is not equal to a ROM, and does
not start like a ROM.

## Directories (`/var/lib/reon-patches`, owner `reonpatch`)

| dir | mode | holds |
| --- | --- | --- |
| `roms/` | 0700 | the official ROMs, named `<sha1>.rom`. Never served; not readable by `reon` (the web user) or nginx |
| `public/` | 0755 | the `.bps` files and `manifest.json`. nginx serves this folder, and only this, at `/patches/` |
| `src/`, `work/`, `home/` | 0700 | clones, scratch, the tool's `$HOME` |

## Commands

```
sudo -u reonpatch reon-patch-build add-rom FILE...   # keep an official ROM (raw or .zip)
sudo -u reonpatch reon-patch-build status            # built / missing ROM, per game
sudo systemctl start reon-patch-build.service        # a run now (or the admin panel: Services -> Game patches)
sudo -u reonpatch reon-patch-build build --only pokecrystal-mobile-fra --force --dry-run
reon-patch-build apply PATCH BASE OUT                # what a player does
```

`add-rom` keeps only ROMs whose SHA-1 the catalog names, so a wrong file
cannot end up in `roms/`.

## Adding a game

Add an entry to `games.json` and run `6-setup-rom-patches.sh` again:

```json
{
  "id": "some-game",                     // becomes the file name
  "title": "What the Downloads card says",
  "language": "en",
  "family": "some-game-family",         // games sharing a family are one choice on the page
  "family_title": "Some Game",
  "repo": "https://github.com/…",
  "rgbds": "0.6.1",                      // must be installed in /opt/reon-toolchain
  "rgbds_style": "prefix",               // prefix: make RGBDS=<bin>/   tools: RGBASM=, RGBLINK=, RGBFIX=
  "output": "game.gbc",                  // what `make` produces
  "build_base": "baserom.gbc",           // only if the repo's build reads the original ROM
  "bases": [{"id": "usa", "region": "USA", "label": "…", "sha1": "…"}]
}
```

A game with several bases gets one patch per base (`<id>-<base id>.bps`). On the Downloads page a menu picks the family, and the `region` of each patch in it becomes a radio button (shown only when there is more than one).
Another rgbds version means one more `build_rgbds` line in the setup script,
with the tarball's SHA-256.

## Stadium 2 (the `stadium` builder)

`pokestadiumgs-mobile` is not a `make` project: one build (the sequence the
maintainers validated on Linux, in `STADIUM_STEPS`) yields seven ROMs, each a
patch over a different official release, so the catalog lists them as
`products` (one base each; Europe serves both the English and the Australian
build) and the official ROMs the build reads as `inputs` (file name it expects
-> SHA-1). The Japanese Kin Gin is an input only: its graphics are read, and
the Japanese game is natively Mobile, so it needs no patch.

What it needs beyond the repositories' own tools:

- the MIPS binutils **2.42** (`/opt/reon-toolchain/mips-binutils`, installed by
  the setup script; another version can change the compiled overlay bytes) and
  Pillow;
- optionally, a **private overlay**, `/var/lib/reon-patches/overlays/<game id>/`
  (mode 0700, never served): files copied into the build's working copy **only
  where the clone lacks them**; its digest is part of the fingerprint. It was
  needed at first, because the repository's `.gitignore` (`assets/`, unanchored)
  kept 79 authored files out of git. The maintainers published them
  (`b0db1c7`), the overlay was emptied, and the build reproduced the same seven
  SHA-1s from the repository alone (the log says `0 file(s) filled in`). The
  mechanism stays for the next time a repository is missing something.
- `tools/bin/turbojpeg.dll` (the repository does not carry it) is **not** needed
  today: with the current art the build never calls it. It becomes required
  only if someone replaces `archive13_055_jpeg_144x96.jpg` with a 4:2:0 JPEG
  outside the Stadium layout, and then the build stops with a clear message.
  The maintainers document where to get it (libjpeg-turbo 3.1.2, the official
  Linux `.deb` unpacked with `dpkg-deb -x`, copied as `tools/bin/turbojpeg.dll`).
- room and time: the whole game list takes about 15 minutes on a fast machine
  (Stadium 2 is most of it); the unit's timeout is two hours.

The published patches must be reproducible from the repository; the
maintainers' expected SHA-1 of the seven outputs is the check.

Not done: converting `.v64`/`.n64` byte order in `add-rom` (the catalog hashes
the big-endian `.z64` dumps).
