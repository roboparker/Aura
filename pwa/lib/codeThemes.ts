import { themes, type PrismTheme } from "prism-react-renderer";

export interface CodeThemePreset {
  id: string;
  label: string;
  light: PrismTheme;
  dark: PrismTheme;
}

// Curated pairs — each preset ships a `light` and `dark` palette so the
// page can paint the right one for whichever mode the user picks. The
// id is the persisted value (localStorage + `html[data-code-theme]`).
export const CODE_THEME_PRESETS: readonly CodeThemePreset[] = [
  { id: "vs", label: "VS Code", light: themes.vsLight, dark: themes.vsDark },
  { id: "github", label: "GitHub", light: themes.github, dark: themes.vsDark },
  {
    id: "nightowl",
    label: "Night Owl",
    light: themes.nightOwlLight,
    dark: themes.nightOwl,
  },
  { id: "dracula", label: "Dracula", light: themes.dracula, dark: themes.dracula },
  { id: "palenight", label: "Palenight", light: themes.palenight, dark: themes.palenight },
  { id: "oceanic", label: "Oceanic Next", light: themes.oceanicNext, dark: themes.oceanicNext },
];

export const DEFAULT_CODE_THEME_ID = "vs";
export const CODE_THEME_STORAGE_KEY = "madori.codeTheme";

export const isKnownCodeThemeId = (id: string | null | undefined): boolean =>
  typeof id === "string" && CODE_THEME_PRESETS.some((p) => p.id === id);
