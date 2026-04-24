import { BlockNoteEditor, PartialBlock } from "@blocknote/core";
import { BlockNoteView } from "@blocknote/mantine";
import { useCreateBlockNote } from "@blocknote/react";
import { useMemo } from "react";
import type { MarkdownEditorProps } from "./MarkdownEditor";

// Parse the incoming markdown once and hand the resulting blocks to the real
// editor. BlockNote only adopts initialContent at creation time, so we compute
// it synchronously up-front; a `key` on the outer MarkdownEditor forces a
// remount when the consumer wants to reset the content.
const MarkdownEditorInner = ({ value, onChange, id, ariaLabel }: MarkdownEditorProps) => {
  const initial = useMemo<PartialBlock[] | undefined>(() => {
    if (!value) return undefined;
    try {
      const tmp = BlockNoteEditor.create();
      const blocks = tmp.tryParseMarkdownToBlocks(value);
      return blocks.length ? blocks : undefined;
    } catch {
      return undefined;
    }
    // Only parse once on mount; ongoing edits flow through BlockNote itself.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const editor = useCreateBlockNote({ initialContent: initial });

  return (
    <div
      id={id}
      aria-label={ariaLabel}
      className="block w-full border border-gray-300 rounded-md bg-white focus-within:ring-2 focus-within:ring-cyan-500 min-h-24 py-1"
    >
      <BlockNoteView
        editor={editor}
        theme="light"
        onChange={() => {
          onChange(editor.blocksToMarkdownLossy(editor.document));
        }}
      />
    </div>
  );
};

export default MarkdownEditorInner;
