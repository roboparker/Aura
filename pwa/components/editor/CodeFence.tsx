import { Fragment, useState } from "react";
import {
  Highlight,
  type Language,
  type PrismTheme,
} from "prism-react-renderer";
import { Check, Copy } from "lucide-react";
import { cn } from "@/lib/utils";
import { CODE_THEME_PRESETS } from "@/lib/codeThemes";
import CodeThemeSwitcher from "./CodeThemeSwitcher";

interface CodeFenceProps {
  code: string;
  language: string;
  className?: string;
}

// Languages prism-react-renderer's `Language` union accepts. Anything
// outside this set falls through to plain monospace styling rather than
// throwing inside Highlight.
const KNOWN_LANGUAGES = new Set<Language>([
  "markup", "bash", "clike", "c", "cpp", "css", "javascript", "jsx",
  "coffeescript", "actionscript", "css-extr", "diff", "git", "go",
  "graphql", "handlebars", "json", "less", "makefile", "markdown",
  "objectivec", "ocaml", "python", "reason", "sass", "scss", "sql",
  "stylus", "tsx", "typescript", "wasm", "yaml",
]);

const normalise = (raw: string): Language | null => {
  const k = raw.toLowerCase();
  if (k === "ts") return "typescript";
  if (k === "js") return "javascript";
  if (k === "sh" || k === "shell" || k === "zsh") return "bash";
  if (k === "yml") return "yaml";
  if (k === "html") return "markup";
  if (k === "php") return "clike";
  return KNOWN_LANGUAGES.has(k as Language) ? (k as Language) : null;
};

interface HighlightedProps {
  code: string;
  language: Language;
  theme: PrismTheme;
}

const Highlighted = ({ code, language, theme }: HighlightedProps) => (
  <Highlight code={code} language={language} theme={theme}>
    {({ className: hlClass, style, tokens, getLineProps, getTokenProps }) => (
      <pre
        className={cn(hlClass, "m-0 overflow-x-auto p-4 text-sm leading-relaxed")}
        style={style}
      >
        {tokens.map((line, i) => {
          const lineProps = getLineProps({ line });
          return (
            <div key={i} {...lineProps}>
              {line.map((token, key) => {
                const tokenProps = getTokenProps({ token });
                return <span key={key} {...tokenProps} />;
              })}
            </div>
          );
        })}
      </pre>
    )}
  </Highlight>
);

// Renders every preset's light + dark variants up-front and tags each
// pane with `data-pane-theme` + `data-pane-mode`. CSS in globals.css
// (.cf-pane rules) shows only the pane that matches the active
// combination of `html[data-code-theme]` and `html.dark`. Doing it
// statically — rather than reading the active preset via React state —
// means SSR-rendered HTML already contains the right pane for every
// possible (preset, mode) combination, so a refresh paints the user's
// saved choice with zero flash.
const CodeFence = ({ code, language, className }: CodeFenceProps) => {
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // clipboard API blocked (insecure context, denied permission) —
      // swallow silently rather than blowing up the page.
    }
  };

  const prismLanguage = normalise(language);

  return (
    <div
      className={cn(
        "group relative my-4 overflow-hidden rounded-md border border-input bg-muted/30",
        className,
      )}
    >
      <div className="flex items-center justify-between border-b border-input/60 bg-muted/40 px-3 py-1 text-xs">
        <span className="font-mono uppercase tracking-wide text-muted-foreground">
          {language || "code"}
        </span>
        <div className="flex items-center gap-1">
          <CodeThemeSwitcher variant="compact" />
          <button
            type="button"
            onClick={handleCopy}
            aria-label="Copy code"
            className="inline-flex h-6 w-6 items-center justify-center rounded-sm text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
          >
            {copied ? (
              <Check className="h-3.5 w-3.5" />
            ) : (
              <Copy className="h-3.5 w-3.5" />
            )}
          </button>
        </div>
      </div>
      {prismLanguage ? (
        CODE_THEME_PRESETS.map((preset) => (
          <Fragment key={preset.id}>
            <div
              className="cf-pane"
              data-pane-theme={preset.id}
              data-pane-mode="light"
            >
              <Highlighted code={code} language={prismLanguage} theme={preset.light} />
            </div>
            <div
              className="cf-pane"
              data-pane-theme={preset.id}
              data-pane-mode="dark"
            >
              <Highlighted code={code} language={prismLanguage} theme={preset.dark} />
            </div>
          </Fragment>
        ))
      ) : (
        <pre className="m-0 overflow-x-auto bg-muted p-4 text-sm leading-relaxed">
          <code>{code}</code>
        </pre>
      )}
    </div>
  );
};

export default CodeFence;
