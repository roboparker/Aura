import { Children, Fragment, type ReactNode } from "react";
import ReactMarkdown, { type Components } from "react-markdown";
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
  // matchAll iteration support (the board's TS target is below es2015).
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

/**
 * Walks a children tree from react-markdown and replaces any string leaf
 * with a mention-aware fragment. react-markdown 10 dropped the `text`
 * component override that earlier versions had; the only stable seam is
 * the per-element renderers (`p`, `li`, `em`, …), so we wire each one to
 * this helper to keep the chip-rendering working.
 */
const wrapMentions = (children: ReactNode): ReactNode => {
  if (typeof children === "string") {
    return renderMentionsInText(children);
  }
  if (Array.isArray(children)) {
    return Children.map(children, (child, i) => (
      <Fragment key={i}>{wrapMentions(child)}</Fragment>
    ));
  }
  return children;
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
  // react-markdown 10 routes inline text through the parent element's
  // `children` array, so we override every common inline-bearing element
  // to chip-wrap their string children. `code` is intentionally absent
  // — we want `@foo` inside an inline code span to stay literal.
  const components: Components | undefined = highlightMentions
    ? {
        p: ({ children }) => <p>{wrapMentions(children)}</p>,
        li: ({ children }) => <li>{wrapMentions(children)}</li>,
        em: ({ children }) => <em>{wrapMentions(children)}</em>,
        strong: ({ children }) => <strong>{wrapMentions(children)}</strong>,
        del: ({ children }) => <del>{wrapMentions(children)}</del>,
        h1: ({ children }) => <h1>{wrapMentions(children)}</h1>,
        h2: ({ children }) => <h2>{wrapMentions(children)}</h2>,
        h3: ({ children }) => <h3>{wrapMentions(children)}</h3>,
        h4: ({ children }) => <h4>{wrapMentions(children)}</h4>,
        h5: ({ children }) => <h5>{wrapMentions(children)}</h5>,
        h6: ({ children }) => <h6>{wrapMentions(children)}</h6>,
        td: ({ children }) => <td>{wrapMentions(children)}</td>,
        th: ({ children }) => <th>{wrapMentions(children)}</th>,
        blockquote: ({ children }) => (
          <blockquote>{wrapMentions(children)}</blockquote>
        ),
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
