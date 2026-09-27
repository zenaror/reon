#!/usr/bin/env python3
# PROVENANCE -- copied verbatim from the "PKHeX Linux Port" session's temporary
# scratchpad on 2026-09-27, before it disappeared with that session. Not written
# by the REON server side. It is the command-line version of what will become
# the plugin's "Compilar dados para REON" button -- same editable fields
# (message of the day, Delibird flags, File ID, cost, slug, region).
#
# Copied unmodified except for this header. Original md5: 95553196e31ef793e797a82e3ea90e1a
#
# Output format (<slug>.bin + <slug>.json) matches what
# maint/import_stadium_distribution.php expects; see spec.md alongside this
# file for the full format this script implements.
"""Build a REON Mobile Stadium distribution pair (<slug>.bin + <slug>.json) from a save's download block.

The block is taken from a save the plugin validated (SRAM bank 7 0xF000, 0x1000 bytes); this script only replaces the
fields a distribution chooses - the message of the day, the Delibird flags and the File ID - and recomputes the frame
("P3" + the little-endian sum of 0x000-0xFFB at 0xFFC) that Stadium needs. The payload served is block[0x000..0xFFD],
exactly 0xFFE bytes, which is what Crystal transfers (mobile_45_stadium.asm; see spec.md).

The same fields will be editable in the plugin's "Compilar dados para REON" button; this is the command-line version.
"""
import argparse, datetime, json, struct, sys
from pathlib import Path

FLAGS = 0xFE1          # Delibird's Delivery flags; bit 0x01 = Game Boy Advance, 0x02 = GameCube (Stadium cannot undo)
FILE_ID = 0xFEA        # 16 bytes, must be new for the game to offer the download
FRAME = 0xFFA          # "P3" then the sum at 0xFFC
MENU = lambda jp: 0xD84 + (0 if jp else 0x30)   # after the three records; the western stride is 0x490
MSG_END = FLAGS        # the message of the day runs up to the flags byte

def build(block: bytearray, jp: bool, message: str | None, flags: int | None, file_id: bytes) -> bytes:
    start = MENU(jp) + 5 * 0x48                 # five rule-record slots, active or not
    if message is not None:
        text = message.encode('ascii')          # Stadium's own markup, e.g. "<FONT LOAD 24>...<LINE 80>"
        room = MSG_END - start
        if len(text) > room:
            sys.exit(f"the message is {len(text)} bytes; at most {room} fit")
        block[start:MSG_END] = text + bytes(room - len(text))
    if flags is not None:
        block[FLAGS] = flags
    block[FILE_ID:FILE_ID + 16] = file_id
    block[FRAME:FRAME + 2] = b'P3'
    block[0xFFC:0xFFE] = struct.pack('<H', sum(block[0:0xFFC]) & 0xFFFF)
    return bytes(block[:0xFFE])

def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument('save'); p.add_argument('outdir')
    p.add_argument('--region', default='j', help="letter the server uses: j e p u d f i s")
    p.add_argument('--slug', required=True)
    p.add_argument('--file-id', required=True, help='16 characters, e.g. 20260927Trailer1')
    p.add_argument('--message', help='message of the day (Stadium markup); omit to keep the save\'s')
    p.add_argument('--flags', type=lambda x: int(x, 0), help='Delibird flags byte, e.g. 0')
    p.add_argument('--cost', default='0', help='null, 0, or a number')
    p.add_argument('--battles', type=int, default=3)
    p.add_argument('--title', default='')
    a = p.parse_args()

    data = Path(a.save).read_bytes()
    block = bytearray(data[0xF000:0x10000])
    if len(block) != 0x1000:
        sys.exit('the save has no 0x1000-byte download block at 0xF000')
    fid = a.file_id.encode('ascii')
    if len(fid) != 16:
        sys.exit('the File ID is 16 bytes')
    payload = build(block, a.region == 'j', a.message, a.flags, fid)

    out = Path(a.outdir); out.mkdir(parents=True, exist_ok=True)
    (out / f'{a.slug}.bin').write_bytes(payload)
    meta = {
        'spec_version': 'crystal-mobile45/2026-09-27',
        'game_region': a.region,
        'file_id': fid.hex(),
        'schedule': {'first_day': 255, 'last_day': 255, 'start_hhmm': 'ffff', 'end_hhmm': 'ffff'},
        'cost': None if a.cost == 'null' else int(a.cost),
        'slug': a.slug,
        'battles': a.battles,
        'title': a.title,
        'generated_by': 'PKHeX Mobile Adapter plugin (internal) + make_distribution.py',
        'generated_at': datetime.datetime.now().astimezone().isoformat(timespec='seconds'),
    }
    (out / f'{a.slug}.json').write_text(json.dumps(meta, ensure_ascii=False, indent=2) + '\n')
    print(f"{a.slug}.bin {len(payload)} bytes, flags 0x{payload[FLAGS]:02X}, sum 0x{struct.unpack('<H', payload[0xFFC:0xFFE])[0]:04X}")

if __name__ == '__main__':
    main()
