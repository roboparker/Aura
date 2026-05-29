import { useState } from "react";
import {
  Highlight,
  themes,
  type Language,
  type PrismTheme,
} from "prism-react-renderer";
import { Check, Copy } from "lucide-react";
import { cn } from "@/lib/utils";

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

// Code fence renderer for the `/guides` markdown pages. Renders BOTH
// light + dark Prism outputs server-side and lets CSS show the right
// one based on the `dark` class next-themes paints onto <html> before
// hydration. Reading the theme via `useTheme()` would race the
// hydration cycle and produce a brief flash of the wrong palette on
// every refresh, since `resolvedTheme` is undefined during SSR.
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
      {prismLanguage ? (
        <>
          <div className="dark:hidden">
            <Highlighted code={code} language={prismLanguage} theme={themes.github} />
          </div>
          <div className="hidden dark:block">
            <Highlighted code={code} language={prismLanguage} theme={themes.vsDark} />
          </div>
        </>
      ) : (
        <pre className="m-0 overflow-x-auto bg-muted p-4 text-sm leading-relaxed">
          <code>{code}</code>
        </pre>
      )}
    </div>
  );
};

export default CodeFence;
