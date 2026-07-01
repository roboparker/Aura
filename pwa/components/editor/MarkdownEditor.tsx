import dynamic from "next/dynamic";
import type { ReactNode } from "react";

export interface MarkdownEditorProps {
  value: string;
  onChange: (markdown: string) => void;
  id?: string;
  ariaLabel?: string;
  /** Optional action bar rendered as a strip at the bottom of the text field
   *  (inside the editor's border), e.g. a submit button for a composer. */
  footer?: ReactNode;
  /** Tailwind min-height class for the typing area (default `min-h-24`). */
  minHeightClass?: string;
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
