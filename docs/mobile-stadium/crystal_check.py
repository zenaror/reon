#!/usr/bin/env python3
"""Offline model of Pokemon Crystal's MOBILE STADIUM download checks (pokecrystal-mobile-eng,
mobile/mobile_46.asm Function119451 / Function1195c4 / Function118e39 and mobile/mobile_45_stadium.asm
Function117bb6 / Function117c89). Read-only: it never writes any file.

usage: crystal_check.py MENU PAYLOAD [SAVE_OR_BLOCK] [--date "Mon, 05 Oct 2026 12:34:56 GMT"]
SAVE_OR_BLOCK: a 64 KB(+RTC) Crystal save (block read at 0xF000) or a raw 0x1000/0xFFE block; default = zeros.
"""
import sys

def crystal_sum(block):                      # Function117c89: sum of 0x000-0xFFB of the STORED block
    return sum(block[:0xFFC]) & 0xFFFF

def cost_of(url):                            # Function118e39 + Function11a14b / Function11a1d6
    name = url[url.rindex('/') + 1:]
    digits = ''
    for ch in name[:4]:
        if ch == '.':                        # digits then '.': the price prompt, or D3 when there were none
            return f'price prompt "Cost: {digits}"' if digits else 'ERROR D3 (name starts with ".")'
        if not ch.isdigit():                 # $F3 written: first byte $F3 = free, else digits + a stray glyph
            return 'free (no price prompt)' if not digits else f'price prompt "Cost: {digits}" + stray glyph'
        digits += ch
    return 'ERROR D3 (4+ leading digits)'

def window_ok(t, now):                       # Function119471 time test; t = 6 file bytes, now = (wday, hour, min)
    s = [t[0], t[2], t[3]]; e = [t[1], t[4], t[5]]
    if s == [0xFF] * 3 and e == [0xFF] * 3:
        return True
    return None                              # anything else: not modelled here, see spec 1.3

def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    menu = open(args[0], 'rb').read()
    payload = open(args[1], 'rb').read()
    if len(args) > 2:
        d = open(args[2], 'rb').read()
        block = d[0xF000:0x10000] if len(d) >= 0x10000 else (d + bytes(0x1000))[:0x1000]
    else:
        block = bytes(0x1000)
    held_id, held_sum = block[0xFEA:0xFFA], crystal_sum(block)
    print(f'save block: File ID {held_id.hex()} {held_id!r}  Crystal sum {held_sum:04X}')

    if len(menu) > 0xFFE:
        print('menu: ERROR D3 (larger than the 0xFFE-byte receive buffer)'); return
    n, p, chosen, any_window = menu[0], 1, None, False
    print(f'menu: {len(menu)} bytes, {n} entr{"y" if n == 1 else "ies"}' + ('  WARNING: count 0 = 256 entries' if n == 0 else ''))
    for i in range(n or 256):
        t, fid, mark, s, ln = menu[p:p+6], menu[p+6:p+22], menu[p+22:p+24], menu[p+24] | menu[p+25] << 8, menu[p+26] | menu[p+27] << 8
        url = menu[p+28:p+28+ln].decode('ascii', 'replace'); p += 28 + ln
        w = window_ok(t, None)
        print(f' entry {i}: window {t.hex()} ({"always" if w else "custom"}) FileID {fid.hex()} marker {mark!r} sum {s:04X} url[{ln}] {url}')
        if not w and w is not None:
            continue
        any_window = True
        if fid != held_id:
            chosen = (i, fid, url, 'new data ("There is data you don\'t have! Read it?")'); break
        if mark == b'P3' and s != held_sum:
            chosen = (i, fid, url, 'same File ID but stored block differs ("read before, but it is gone or broken")'); break
        print('   -> skipped: this File ID is already held' + (' and its sum matches' if mark == b'P3' else ' (marker not P3: never re-offered)'))
    if chosen is None:
        print('result:', '"This data already exists!" (code 0x0A, no download)' if any_window else 'ERROR D8 (no distribution running)')
        return
    i, fid, url, why = chosen
    if len(url) > 0xA5:
        print('result: ERROR D8 (URL longer than 0xA5)'); return
    print(f'selected entry {i}: {why}; {cost_of(url)}')
    ok = True
    if len(payload) != 0xFFE:
        print(f'payload: ERROR D3 - length {len(payload):#x}, Crystal requires exactly 0xFFE'); ok = False
    if payload[0xFEA:0xFFA] != fid:
        print(f'payload: ERROR D3 - File ID at 0xFEA {payload[0xFEA:0xFFA].hex()} != menu entry {fid.hex()}'); ok = False
    if ok:
        print('payload: ACCEPTED by Crystal -> copied to SRAM bank 7 0xB000 (save 0xF000), 0x1000 bytes')
    # What Stadium 2 will think (not checked by Crystal)
    fr = payload[0xFFA:0xFFC] == b'P3' and (payload[0xFFC] | payload[0xFFD] << 8) == crystal_sum(payload)
    print(f'stadium frame (P3 + sum at 0xFFA): {"valid" if fr else "MISSING/INVALID - Stadium lists nothing"}; '
          f'header counts {payload[0]}/{payload[1] if len(payload) > 1 else "?"}; Delibird flags 0xFE1 = {payload[0xFE1] if len(payload) > 0xFE1 else "?"}')

main()
