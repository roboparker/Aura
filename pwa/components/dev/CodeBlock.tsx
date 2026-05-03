import { useState } from "react";
import { Highlight, themes, type Language } from "prism-react-renderer";
import { useTheme } from "next-themes";
import { Check, Copy } from "lucide-react";
import { cn } from "@/lib/utils";

type CodeBlockProps = {
  code: string;
  language?: Language;
  className?: string;
};

const CodeBlock = ({ code, language = "tsx", className }: CodeBlockProps) => {
  const { resolvedTheme } = useTheme();
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    await navigator.clipboard.writeText(code);
    setCopied(true);
    window.setTimeout(() => setCopied(false), 1500);
  };

  // `themes` is from prism-react-renderer; pick palette to match active mode.
  const prismTheme = resolvedTheme === "dark" ? themes.vsDark : themes.github;

  return (
    <div className={cn("group relative overflow-hidden rounded-md border border-input bg-muted/30", className)}>
      <button
        type="button"
        onClick={handleCopy}
        aria-label="Copy code"
        className="absolute right-2 top-2 z-10 inline-flex h-7 w-7 items-center justify-center rounded-sm border border-input bg-background/80 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover:opacity-100 focus-visible:opacity-100"
      >
        {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
      </button>
      <Highlight code={code.trim()} language={language} theme={prismTheme}>
        {({ className: hlClass, style, tokens, getLineProps, getTokenProps }) => (
          <pre
            className={cn(hlClass, "overflow-x-auto p-4 text-xs leading-relaxed")}
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
    </div>
  );
};

export default CodeBlock;
