import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

interface MarkdownViewProps {
  source: string | null | undefined;
  className?: string;
}

// Read-only renderer for stored markdown. react-markdown escapes raw HTML by
// default (we don't enable rehype-raw), so user input can't inject script tags.
// Styling lives in globals.css under `.markdown-view` since we don't ship the
// tailwind-typography plugin.
const MarkdownView = ({ source, className }: MarkdownViewProps) => {
  if (!source) return null;
  return (
    <div className={`markdown-view ${className ?? ""}`}>
      <ReactMarkdown remarkPlugins={[remarkGfm]}>{source}</ReactMarkdown>
    </div>
  );
};

export default MarkdownView;
