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
