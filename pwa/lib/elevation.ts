/**
 * Surface elevation for form controls.
 *
 * The dark theme stacks lighter = higher: background (page) < card/popover <
 * muted < accent. Form fields pick a level so they read as "raised" off the
 * page. Level 0 is special — "inline": no border or background at rest, so a
 * control sits flush in a table cell like plain text, revealing a border only
 * on hover (with a text/pointer cursor) and on focus while editing.
 *
 * Pass `elevation={0}` to the form components in the board list view; the
 * task drawer and standalone forms keep the default raised look (level 1).
 */
export type Elevation = 0 | 1 | 2 | 3;

/**
 * Border / background / shadow classes for an `<input>` / `<textarea>`-style
 * control at the given elevation. Focus + hover affordances are baked in.
 */
export const inputElevationClass = (level: Elevation = 1): string => {
  switch (level) {
    case 0:
      return "min-h-11 rounded-none border border-transparent bg-transparent shadow-none hover:border-input focus-visible:border-input";
    case 2:
      return "border border-input bg-muted shadow-sm focus-visible:border-white";
    case 3:
      return "border border-input bg-accent shadow-sm focus-visible:border-white";
    case 1:
    default:
      return "border border-input bg-transparent shadow-sm dark:bg-input/30 focus-visible:border-white";
  }
};

/**
 * The same scale for a {@link ComboboxChips}-style multi-control, whose focus
 * state is `focus-within` (a child input takes focus) rather than
 * `focus-visible`.
 */
export const chipsElevationClass = (level: Elevation = 1): string => {
  switch (level) {
    case 0:
      return "min-h-11 rounded-none border border-transparent bg-transparent shadow-none hover:border-input focus-within:border-input";
    case 2:
      return "border border-input bg-muted shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50";
    case 3:
      return "border border-input bg-accent shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50";
    case 1:
    default:
      return "border border-input bg-transparent shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30";
  }
};
