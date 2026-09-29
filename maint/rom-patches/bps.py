"""BPS patches (byuu's format): create, apply, check. Standard library only.

The applier is deliberately a separate code path from the creator: a patch is
only published after it has been applied to the base ROM with apply() and the
result compared with the ROM that was built, so a bug in create() cannot ship a
patch that does not reproduce the build.
"""
import struct
import zlib

MAGIC = b"BPS1"

SOURCE_READ, TARGET_READ, SOURCE_COPY, TARGET_COPY = range(4)


class BpsError(Exception):
    pass


def _encode_number(n):
    out = bytearray()
    while True:
        x = n & 0x7F
        n >>= 7
        if n == 0:
            out.append(0x80 | x)
            return bytes(out)
        out.append(x)
        n -= 1


def _common(a, ai, b, bi):
    """Length of the common prefix of a[ai:] and b[bi:]."""
    limit = min(len(a) - ai, len(b) - bi)
    n = 0
    while n + 256 <= limit and a[ai + n:ai + n + 256] == b[bi + n:bi + n + 256]:
        n += 256
    while n < limit and a[ai + n] == b[bi + n]:
        n += 1
    return n


def create(source, target, metadata=b""):
    """Return a BPS patch that turns `source` into `target`."""
    source, target = bytes(source), bytes(target)
    window = 8
    # Index every `stride`-th window of the source. A match at least
    # stride + window - 1 bytes long always contains an indexed window, and the
    # target is probed at every position, so nothing that long is missed while
    # the index stays around a million entries whatever the ROM's size.
    stride = max(1, -(-len(source) // 1_000_000))
    index = {}
    for i in range(0, len(source) - window + 1, stride):
        index.setdefault(hash(source[i:i + window]), i)

    out = bytearray(MAGIC)
    out += _encode_number(len(source))
    out += _encode_number(len(target))
    out += _encode_number(len(metadata))
    out += metadata

    n = len(target)
    pos = 0
    lit = 0                 # start of the literal run not written yet
    src_rel = 0             # SourceCopy cursor
    tgt_rel = 0             # TargetCopy cursor

    def flush(upto):
        nonlocal lit
        if upto > lit:
            out.extend(_encode_number(((upto - lit - 1) << 2) | TARGET_READ))
            out.extend(target[lit:upto])
        lit = upto

    while pos < n:
        kind, length, where = None, 0, 0

        # Same offset in the source: the cheapest thing to say.
        if pos < len(source) and source[pos] == target[pos]:
            length = _common(source, pos, target, pos)
            if length >= 4:
                kind = SOURCE_READ
            else:
                length = 0

        # A run of one byte: TargetCopy from one byte back.
        if pos > 0 and target[pos] == target[pos - 1]:
            run = _common(target, pos - 1, target, pos)
            if run >= 8 and run > length:
                kind, length, where = TARGET_COPY, run, pos - 1

        # Anywhere else in the source.
        if pos + window <= n:
            cand = index.get(hash(target[pos:pos + window]))
            if cand is not None:
                fwd = _common(source, cand, target, pos)
                # Pull the start back into the literals still pending.
                back = 0
                while (back < pos - lit and cand - back > 0
                       and source[cand - back - 1] == target[pos - back - 1]):
                    back += 1
                if fwd + back >= 8 and fwd + back > length + 2:
                    kind, length, where = SOURCE_COPY, fwd + back, cand - back
                    pos -= back

        if kind is None:
            pos += 1
            continue

        flush(pos)
        out.extend(_encode_number(((length - 1) << 2) | kind))
        if kind == SOURCE_COPY:
            delta = where - src_rel
            out.extend(_encode_number((abs(delta) << 1) | (1 if delta < 0 else 0)))
            src_rel = where + length
        elif kind == TARGET_COPY:
            delta = where - tgt_rel
            out.extend(_encode_number((abs(delta) << 1) | (1 if delta < 0 else 0)))
            tgt_rel = where + length
        pos += length
        lit = pos
    flush(n)

    out += struct.pack("<I", zlib.crc32(source))
    out += struct.pack("<I", zlib.crc32(target))
    out += struct.pack("<I", zlib.crc32(bytes(out)))
    return bytes(out)


def apply(patch, source):
    """Return the target the patch describes, or raise BpsError."""
    patch, source = bytes(patch), bytes(source)
    if len(patch) < 4 + 3 + 12 or patch[:4] != MAGIC:
        raise BpsError("not a BPS patch")
    if zlib.crc32(patch[:-4]) != struct.unpack("<I", patch[-4:])[0]:
        raise BpsError("patch checksum does not match (corrupt patch)")
    src_crc, tgt_crc = struct.unpack("<II", patch[-12:-4])
    end = len(patch) - 12
    at = 4

    def number():
        nonlocal at
        data, shift = 0, 1
        while True:
            if at >= end:
                raise BpsError("truncated patch")
            x = patch[at]
            at += 1
            data += (x & 0x7F) * shift
            if x & 0x80:
                return data
            shift <<= 7
            data += shift

    src_size, tgt_size, meta_size = number(), number(), number()
    at += meta_size
    if at > end:
        raise BpsError("truncated patch")
    if len(source) != src_size:
        raise BpsError("base ROM is %d bytes, the patch expects %d" % (len(source), src_size))
    if zlib.crc32(source) != src_crc:
        raise BpsError("base ROM is not the one this patch was made for")

    target = bytearray()
    src_rel = tgt_rel = 0
    while at < end:
        data = number()
        kind, length = data & 3, (data >> 2) + 1
        if len(target) + length > tgt_size:
            raise BpsError("patch writes past the end of the target")
        if kind == SOURCE_READ:
            o = len(target)
            if o + length > len(source):
                raise BpsError("SourceRead beyond the base ROM")
            target += source[o:o + length]
        elif kind == TARGET_READ:
            if at + length > end:
                raise BpsError("truncated patch")
            target += patch[at:at + length]
            at += length
        else:
            rel = number()
            delta = -(rel >> 1) if rel & 1 else rel >> 1
            if kind == SOURCE_COPY:
                src_rel += delta
                if src_rel < 0 or src_rel + length > len(source):
                    raise BpsError("SourceCopy outside the base ROM")
                target += source[src_rel:src_rel + length]
                src_rel += length
            else:
                tgt_rel += delta
                if tgt_rel < 0 or tgt_rel >= len(target):
                    raise BpsError("TargetCopy outside the output")
                for _ in range(length):   # may overlap what it is writing
                    target.append(target[tgt_rel])
                    tgt_rel += 1
    if len(target) != tgt_size:
        raise BpsError("patch produced %d bytes, expected %d" % (len(target), tgt_size))
    if zlib.crc32(bytes(target)) != tgt_crc:
        raise BpsError("result does not match the patch's checksum")
    return bytes(target)
