#!/usr/bin/env python3
"""One-shot generator for pwa/public/favicon.ico.

Renders the same mark as pwa/public/favicon.svg (white "A" strokes on a
rounded cyan #0e7490 square) at 16/32/48 px with 4x supersampling and
packs the PNGs into a single ICO. Dependency-free (stdlib zlib only).
"""
import math
import struct
import sys
import zlib

CYAN = (0x0E, 0x74, 0x90)
WHITE = (0xFF, 0xFF, 0xFF)
RADIUS = 22.0  # rounded-rect corner radius in the 100-unit viewBox
STROKE = 12.0  # "A" stroke width
SEGMENTS = [
    ((31.0, 78.0), (50.0, 24.0)),
    ((50.0, 24.0), (69.0, 78.0)),
    ((38.5, 62.0), (61.5, 62.0)),
]


def seg_dist(px, py, a, b):
    ax, ay = a
    bx, by = b
    dx, dy = bx - ax, by - ay
    t = ((px - ax) * dx + (py - ay) * dy) / (dx * dx + dy * dy)
    t = max(0.0, min(1.0, t))
    return math.hypot(px - (ax + t * dx), py - (ay + t * dy))


def rounded_rect_inside(px, py):
    # Signed test for the 0..100 box with corner radius RADIUS.
    qx = abs(px - 50.0) - (50.0 - RADIUS)
    qy = abs(py - 50.0) - (50.0 - RADIUS)
    if qx <= 0.0 or qy <= 0.0:
        return 0.0 <= px <= 100.0 and 0.0 <= py <= 100.0
    return math.hypot(qx, qy) <= RADIUS


def render(size, ss=4):
    half_stroke = STROKE / 2.0
    rows = []
    scale = 100.0 / (size * ss)
    for y in range(size):
        row = bytearray()
        for x in range(size):
            bg_hits = 0
            glyph_hits = 0
            for sy in range(ss):
                for sx in range(ss):
                    px = (x * ss + sx + 0.5) * scale
                    py = (y * ss + sy + 0.5) * scale
                    if not rounded_rect_inside(px, py):
                        continue
                    bg_hits += 1
                    if any(seg_dist(px, py, a, b) <= half_stroke for a, b in SEGMENTS):
                        glyph_hits += 1
            total = ss * ss
            alpha = round(255 * bg_hits / total)
            if bg_hits:
                mix = glyph_hits / bg_hits
                r = round(CYAN[0] + (WHITE[0] - CYAN[0]) * mix)
                g = round(CYAN[1] + (WHITE[1] - CYAN[1]) * mix)
                b = round(CYAN[2] + (WHITE[2] - CYAN[2]) * mix)
            else:
                r = g = b = 0
            row += bytes((r, g, b, alpha))
        rows.append(bytes(row))
    return rows


def png_chunk(tag, data):
    return struct.pack(">I", len(data)) + tag + data + struct.pack(
        ">I", zlib.crc32(tag + data)
    )


def to_png(size, rows):
    raw = b"".join(b"\x00" + row for row in rows)
    return (
        b"\x89PNG\r\n\x1a\n"
        + png_chunk(b"IHDR", struct.pack(">IIBBBBB", size, size, 8, 6, 0, 0, 0))
        + png_chunk(b"IDAT", zlib.compress(raw, 9))
        + png_chunk(b"IEND", b"")
    )


def main(out_path):
    sizes = (16, 32, 48)
    pngs = [to_png(s, render(s)) for s in sizes]
    ico = struct.pack("<HHH", 0, 1, len(sizes))
    offset = 6 + 16 * len(sizes)
    for s, png in zip(sizes, pngs):
        ico += struct.pack("<BBBBHHII", s % 256, s % 256, 0, 0, 1, 32, len(png), offset)
        offset += len(png)
    with open(out_path, "wb") as f:
        f.write(ico + b"".join(pngs))
    print(f"wrote {out_path} ({offset} bytes)")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "pwa/public/favicon.ico")
