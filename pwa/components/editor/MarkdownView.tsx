import { Fragment, type ReactNode } from "react";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

interface MarkdownViewProps {
  source: string | null | undefined;
  className?: string;
  /**
   * When true, `@token` runs inside text nodes render as styled mention
   * chips. Used in the comment view to highlight resolved mentions
   * created by the server-side parser (#106). The same pattern as the
   * server: alphanumerics + `._+-`, bounded by start-of-string or
   * whitespace so plain emails (`foo@bar.com`) stay un-styled.
   */
  highlightMentions?: boolean;
}

const MENTION_PATTERN = /(^|\s)(@[A-Za-z0-9._+-]+)/g;

const renderMentionsInText = (input: string): ReactNode => {
  if (!input.includes("@")) return input;
  const parts: ReactNode[] = [];
  let cursor = 0;
  let match: RegExpExecArray | null;
  // Reset and reuse a single regex instance so we don't depend on
  // matchAll iteration support (the project's TS target is below es2015).
  MENTION_PATTERN.lastIndex = 0;
  while ((match = MENTION_PATTERN.exec(input)) !== null) {
    const idx = match.index;
    const lead = match[1] ?? "";
    const mention = match[2] ?? "";
    const start = idx + lead.length;
    if (start > cursor) parts.push(input.slice(cursor, start));
    parts.push(
      <span
        key={`m-${start}`}
        className="inline-flex items-center rounded bg-cyan-50 px-1 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300"
        data-testid="comment-mention"
      >
        {mention}
      </span>,
    );
    cursor = start + mention.length;
  }
  if (cursor < input.length) parts.push(input.slice(cursor));
  return (
    <>
      {parts.map((p, i) => (
        <Fragment key={i}>{p}</Fragment>
      ))}
    </>
  );
};

// Read-only renderer for stored markdown. react-markdown escapes raw HTML by
// default (we don't enable rehype-raw), so user input can't inject script tags.
// Styling lives in globals.css under `.markdown-view` since we don't ship the
// tailwind-typography plugin.
const MarkdownView = ({
  source,
  className,
  highlightMentions,
}: MarkdownViewProps) => {
  if (!source) return null;
  // react-markdown delivers each text leaf as a string child; we walk
  // it and replace @mention runs with styled spans. Block structure is
  // preserved because only the leaf renderer is overridden.
  const components = highlightMentions
    ? {
        text: ({ children }: { children?: ReactNode }) =>
          typeof children === "string"
            ? renderMentionsInText(children)
            : children,
      }
    : undefined;
  return (
    <div className={`markdown-view ${className ?? ""}`}>
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {source}
      </ReactMarkdown>
    </div>
  );
};

export default MarkdownView;
