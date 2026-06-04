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
 * `group.color` wins; otherwise inherit the owner's `personalizedColor`;
 * otherwise fall back to the first palette entry. Mirrors
 * {@link resolveSpaceColor} so groups and spaces feel of-a-piece.
 */
export function resolveGroupColor(group: {
  color?: string | null;
  owner?: { personalizedColor?: string | null } | null;
}): string {
  return (
    group.color ??
    group.owner?.personalizedColor ??
    AVATAR_PALETTE[0]
  );
}
