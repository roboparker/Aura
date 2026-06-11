import { Html, Head, Main, NextScript } from "next/document";
import {
  CODE_THEME_PRESETS,
  CODE_THEME_STORAGE_KEY,
  DEFAULT_CODE_THEME_ID,
} from "@/lib/codeThemes";

// Runs BEFORE React hydrates. Reads the persisted code-theme id from
// localStorage and stamps it onto `<html data-code-theme="...">`, which
// the CSS rules in globals.css use to show only the matching code-fence
// pane. Doing this synchronously before paint is what keeps refreshes
// flash-free even when the user has picked a non-default theme.
const codeThemeBootstrap = `
(function() {
  try {
    var ids = ${JSON.stringify(CODE_THEME_PRESETS.map((p) => p.id))};
    var saved = localStorage.getItem(${JSON.stringify(CODE_THEME_STORAGE_KEY)});
    var id = saved && ids.indexOf(saved) !== -1 ? saved : ${JSON.stringify(DEFAULT_CODE_THEME_ID)};
    document.documentElement.setAttribute('data-code-theme', id);
  } catch (e) {
    document.documentElement.setAttribute('data-code-theme', ${JSON.stringify(DEFAULT_CODE_THEME_ID)});
  }
})();
`.trim();

const Document = () => (
  <Html
    lang="en"
    // Aura is dark-only. The `dark` class is hard-coded here so the
    // app paints dark on first byte (no flash, no client toggle) and
    // Tailwind's `dark:` variants + the code-fence `html.dark` rules
    // keep resolving.
    className="dark"
    data-code-theme={DEFAULT_CODE_THEME_ID}
    suppressHydrationWarning
  >
    <Head>
      <link rel="icon" href="/favicon.ico" sizes="48x48" />
      <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
      <script dangerouslySetInnerHTML={{ __html: codeThemeBootstrap }} />
    </Head>
    <body>
      <Main />
      <NextScript />
    </body>
  </Html>
);

export default Document;
