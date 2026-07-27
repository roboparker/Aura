/**
 * WCAG contrast helpers.
 *
 * Used where a foreground has to sit on a colour we don't control at build
 * time — a user-picked tag or group colour dropped into an inline style. The
 * palette in `avatarPalette.ts` is pre-verified against white, but values can
 * also arrive from the API, from fixtures, or from rows created before a
 * constraint existed, so rendering has to stay legible regardless.
 */

const WHITE = "#ffffff";
/** Near-black rather than pure black: softer on light chips, same AA maths. */
const NEAR_BLACK = "#111317";

/** Parses `#rgb` / `#rrggbb` into 0-255 channels. Returns null if unparseable. */
export function parseHex(hex: string): { r: number; g: number; b: number } | null {
  const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return null;
  let h = m[1];
  if (h.length === 3) {
    h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
  }
  return {
    r: parseInt(h.slice(0, 2), 16),
    g: parseInt(h.slice(2, 4), 16),
    b: parseInt(h.slice(4, 6), 16),
  };
}

/** WCAG 2.x relative luminance. */
export function relativeLuminance(hex: string): number {
  const c = parseHex(hex);
  if (!c) return 0;
  const channel = (v: number) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(c.r) + 0.7152 * channel(c.g) + 0.0722 * channel(c.b);
}

/** WCAG contrast ratio between two hex colours, 1..21. */
export function contrastRatio(a: string, b: string): number {
  const la = relativeLuminance(a);
  const lb = relativeLuminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/**
 * Picks whichever of white / near-black reads better on `background`.
 *
 * Deliberately compares the two candidates rather than testing lightness
 * against a fixed threshold: a mid-tone like amber-500 is a coin toss by
 * lightness but decisively better with dark text (2.15:1 white vs 8.9:1
 * near-black). Falls back to white on an unparseable value, matching the
 * previous hardcoded behaviour.
 */
export function readableForeground(background: string): string {
  if (!parseHex(background)) return WHITE;
  return contrastRatio(WHITE, background) >= contrastRatio(NEAR_BLACK, background)
    ? WHITE
    : NEAR_BLACK;
}

/** True when `background` can carry normal-size body text at AA (4.5:1). */
export function meetsAA(background: string, foreground = readableForeground(background)): boolean {
  return contrastRatio(foreground, background) >= 4.5;
}
