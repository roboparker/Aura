// Mirror of api/src/Service/AvatarColorService.php PALETTE. All entries
// are pre-verified to meet WCAG AA contrast (>= 4.5:1) against white text.
// If you change the list here, change the PHP constant too — the backend
// validates submitted colors against its own copy.
export const AVATAR_PALETTE: readonly string[] = [
  "#334155", // slate-700
  "#b91c1c", // red-700
  "#c2410c", // orange-700
  "#b45309", // amber-700
  "#854d0e", // yellow-800
  "#4d7c0f", // lime-700
  "#15803d", // green-700
  "#047857", // emerald-700
  "#0f766e", // teal-700
  "#0e7490", // cyan-700
  "#0369a1", // sky-700
  "#1d4ed8", // blue-700
  "#4338ca", // indigo-700
  "#6d28d9", // violet-700
  "#7e22ce", // purple-700
  "#be185d", // pink-700
];

/**
 * Speakable names for the palette, keyed by hex.
 *
 * The colours were already named in the comments above; this makes the name
 * addressable so a swatch can label itself "Teal" instead of handing a screen
 * reader a hex code to read out as "hash three three four one five five".
 * Shade suffixes are dropped — "slate-700" is an implementation detail, and
 * the palette holds one shade per hue, so "Slate" is unambiguous.
 */
export const PALETTE_NAMES: Readonly<Record<string, string>> = {
  "#334155": "Slate",
  "#b91c1c": "Red",
  "#c2410c": "Orange",
  "#b45309": "Amber",
  "#854d0e": "Yellow",
  "#4d7c0f": "Lime",
  "#15803d": "Green",
  "#047857": "Emerald",
  "#0f766e": "Teal",
  "#0e7490": "Cyan",
  "#0369a1": "Sky",
  "#1d4ed8": "Blue",
  "#4338ca": "Indigo",
  "#6d28d9": "Violet",
  "#7e22ce": "Purple",
  "#be185d": "Pink",
};

/**
 * Human name for a palette colour, falling back to the hex for anything
 * off-palette. The fallback is no worse than the previous behaviour, and
 * every colour the app actually offers is in the map.
 */
export function colorName(hex: string): string {
  return PALETTE_NAMES[hex.trim().toLowerCase()] ?? hex;
}

/**
 * Deterministic palette pick keyed on a string (name, id — anything
 * stable for the entity in question). Use for surfaces that need a
 * persistent color but don't have a `personalizedColor` column to read
 * from — groups, anywhere we want the avatar tile to feel "owned" by
 * the entity without a schema change.
 *
 * Hash is intentionally simple: a Tailwind palette of 16 means a fancy
 * hash buys us nothing; the goal is "same string → same swatch every
 * time", not collision resistance.
 */
export function deterministicPaletteColor(seed: string): string {
  if (seed.length === 0) return AVATAR_PALETTE[0];
  let h = 0;
  for (let i = 0; i < seed.length; i++) {
    h = (h * 31 + seed.charCodeAt(i)) % AVATAR_PALETTE.length;
  }
  return AVATAR_PALETTE[h];
}

/** A random color from the WCAG-AA palette — used when creating something
 *  inline (e.g. a tag) without an explicit color, so it isn't always grey. */
export function randomPaletteColor(): string {
  return AVATAR_PALETTE[Math.floor(Math.random() * AVATAR_PALETTE.length)];
}

/**
 * Resolve the color to render for a Space's avatar tile, following the
 * spec: explicit `space.color` wins; otherwise inherit the creator's
 * `personalizedColor`; otherwise fall back to the first palette entry
 * so we never render a transparent tile.
 *
 * Same behavior for personal and shared spaces — no branching — so the
 * tile can stay a pure presentation prop.
 */
export function resolveSpaceColor(space: {
  color: string | null;
  createdBy: { personalizedColor?: string } | null;
}): string {
  return (
    space.color ??
    space.createdBy?.personalizedColor ??
    AVATAR_PALETTE[0]
  );
}

/**
 * Resolve the color to render for a group's avatar tile: explicit
 * `group.color` wins; otherwise inherit the owning space's color; otherwise
 * fall back to the first palette entry. Mirrors {@link resolveSpaceColor} so
 * groups and spaces feel of-a-piece.
 */
export function resolveGroupColor(group: {
  color?: string | null;
  spaceSummary?: { color?: string | null } | null;
}): string {
  return (
    group.color ??
    group.spaceSummary?.color ??
    AVATAR_PALETTE[0]
  );
}
