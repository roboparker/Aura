import dynamic from "next/dynamic";

export interface MarkdownEditorProps {
  value: string;
  onChange: (markdown: string) => void;
  id?: string;
  ariaLabel?: string;
}

// BlockNote touches the DOM on import and ships its own Mantine-based styles.
// Loading it only on the client keeps Next.js SSR happy and avoids hydration drift.
const MarkdownEditorInner = dynamic(() => import("./MarkdownEditorInner"), {
  ssr: false,
  loading: () => (
    <div className="block w-full border border-input rounded-md px-3 py-2 bg-muted animate-pulse min-h-24" />
  ),
});

const MarkdownEditor = (props: MarkdownEditorProps) => <MarkdownEditorInner {...props} />;

export default MarkdownEditor;
