#!/usr/bin/env python3
"""Generate the SignaturePortal add-in pen icons as transparent 32-bit PNGs.

Pure Python (zlib + struct): rasterizes a fountain-pen + signature-swash
glyph via signed distance functions with 4x4 supersampling per pixel.
Outputs icon-{16,20,24,32,40,48,64,80,128}.png into the target directory.
"""
import math
import struct
import sys
import zlib

SIZES = [16, 20, 24, 32, 40, 48, 64, 80, 128]

# Colors (sRGB): pen in dark gray, signature swash in the portal blue.
PEN_COLOR = (72, 72, 72)
SWASH_COLOR = (47, 111, 235)  # #2f6feb

# ---- geometry in unit canvas (0..1, y down) --------------------------------
# Pen points down-left at 45 deg. Tip at TIP, tail toward top-right.
# y is down, so "toward top-right" is (+x, -y).
TIP = (0.26, 0.74)
AXIS = (math.sqrt(0.5), -math.sqrt(0.5))


def along(t):
    """Point at distance t from tip along the pen axis (toward tail)."""
    return (TIP[0] + AXIS[0] * t, TIP[1] + AXIS[1] * t)


NIB_LEN = 0.26
NIB_HALF = 0.105
BODY_START = 0.30
BODY_END = 0.78
BODY_HALF = 0.10
HOLE_T = 0.115
HOLE_R = 0.033

# Signature swash: polyline approximation of a gentle S-curve under the pen.
SWASH_PTS = []
for i in range(33):
    s = i / 32.0
    x = 0.06 + 0.60 * s
    y = 0.90 + 0.045 * math.sin(s * math.pi * 2.0) * (1.0 - 0.3 * s)
    SWASH_PTS.append((x, y))
SWASH_HALF = 0.038


def rot(p):
    """Rotate into pen frame: u along axis from tip, v perpendicular."""
    dx, dy = p[0] - TIP[0], p[1] - TIP[1]
    u = dx * AXIS[0] + dy * AXIS[1]
    v = -dx * AXIS[1] + dy * AXIS[0]
    return u, v


def sd_capsule(p, a, b, r):
    pax, pay = p[0] - a[0], p[1] - a[1]
    bax, bay = b[0] - a[0], b[1] - a[1]
    h = max(0.0, min(1.0, (pax * bax + pay * bay) / (bax * bax + bay * bay)))
    dx, dy = pax - bax * h, pay - bay * h
    return math.hypot(dx, dy) - r


def sd_nib(p):
    """Wedge from tip widening to NIB_HALF at NIB_LEN, slightly rounded tip."""
    u, v = rot(p)
    # distance to triangle (0,0), (NIB_LEN, NIB_HALF), (NIB_LEN, -NIB_HALF)
    verts = [(0.0, 0.0), (NIB_LEN, NIB_HALF), (NIB_LEN, -NIB_HALF)]
    return sd_polygon((u, v), verts) - 0.012  # rounding


def sd_polygon(p, verts):
    d = float("inf")
    s = 1.0
    n = len(verts)
    j = n - 1
    for i in range(n):
        ex, ey = verts[j][0] - verts[i][0], verts[j][1] - verts[i][1]
        wx, wy = p[0] - verts[i][0], p[1] - verts[i][1]
        h = max(0.0, min(1.0, (wx * ex + wy * ey) / (ex * ex + ey * ey)))
        bx, by = wx - ex * h, wy - ey * h
        d = min(d, bx * bx + by * by)
        c1 = p[1] >= verts[i][1]
        c2 = p[1] < verts[j][1]
        c3 = ex * wy > ey * wx
        if (c1 and c2 and c3) or ((not c1) and (not c2) and (not c3)):
            s = -s
        j = i
    return s * math.sqrt(d)


def sd_pen(p):
    body = sd_capsule(p, along(BODY_START + 0.02), along(BODY_END), BODY_HALF)
    nib = sd_nib(p)
    d = min(body, nib)
    # nib breather hole (cut out)
    hu, hv = rot(p)
    hole = math.hypot(hu - HOLE_T, hv) - HOLE_R
    return max(d, -hole)


def sd_swash(p):
    d = float("inf")
    for i in range(len(SWASH_PTS) - 1):
        d = min(d, sd_capsule(p, SWASH_PTS[i], SWASH_PTS[i + 1], 0.0))
    return d - SWASH_HALF


def render(size):
    ss = 4  # supersamples per axis
    px = 1.0 / size
    rows = []
    for y in range(size):
        row = bytearray()
        for x in range(size):
            a_pen = a_swash = 0.0
            for sy in range(ss):
                for sx in range(ss):
                    u = (x + (sx + 0.5) / ss) * px
                    v = (y + (sy + 0.5) / ss) * px
                    p = (u, v)
                    if sd_pen(p) < 0:
                        a_pen += 1
                    elif sd_swash(p) < 0:
                        a_swash += 1
            n = ss * ss
            a_pen /= n
            a_swash /= n
            alpha = a_pen + a_swash
            if alpha <= 0:
                row += b"\x00\x00\x00\x00"
                continue
            r = (PEN_COLOR[0] * a_pen + SWASH_COLOR[0] * a_swash) / alpha
            g = (PEN_COLOR[1] * a_pen + SWASH_COLOR[1] * a_swash) / alpha
            b = (PEN_COLOR[2] * a_pen + SWASH_COLOR[2] * a_swash) / alpha
            row += bytes((int(round(r)), int(round(g)), int(round(b)), int(round(alpha * 255))))
        rows.append(bytes(row))
    return rows


def write_png(path, size, rows):
    raw = b"".join(b"\x00" + r for r in rows)

    def chunk(tag, data):
        c = tag + data
        return struct.pack(">I", len(data)) + c + struct.pack(">I", zlib.crc32(c) & 0xFFFFFFFF)

    ihdr = struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0)
    png = (b"\x89PNG\r\n\x1a\n"
           + chunk(b"IHDR", ihdr)
           + chunk(b"IDAT", zlib.compress(raw, 9))
           + chunk(b"IEND", b""))
    with open(path, "wb") as f:
        f.write(png)


def main(outdir):
    for size in SIZES:
        rows = render(size)
        path = f"{outdir}/icon-{size}.png"
        write_png(path, size, rows)
        print(f"wrote {path}")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else ".")
