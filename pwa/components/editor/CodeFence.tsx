import { useState } from "react";
import { Highlight, themes, type Language } from "prism-react-renderer";
import { useTheme } from "next-themes";
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

// Code fence renderer for the `/guides` markdown pages. Mirrors the
// component-library CodeBlock (theme-aware, copy button), but takes its
// code + language as plain strings so it can be slotted in as react-
// markdown's `<pre>` override.
const CodeFence = ({ code, language, className }: CodeFenceProps) => {
  const { resolvedTheme } = useTheme();
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
  const prismTheme = resolvedTheme === "dark" ? themes.vsDark : themes.github;

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
        <Highlight code={code} language={prismLanguage} theme={prismTheme}>
          {({ className: hlClass, style, tokens, getLineProps, getTokenProps }) => (
            <pre
              className={cn(hlClass, "overflow-x-auto p-4 text-sm leading-relaxed")}
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
      ) : (
        <pre className="overflow-x-auto bg-muted p-4 text-sm leading-relaxed">
          <code>{code}</code>
        </pre>
      )}
    </div>
  );
};

export default CodeFence;
