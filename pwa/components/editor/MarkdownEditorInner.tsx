import { BlockNoteEditor, PartialBlock } from "@blocknote/core";
import { BlockNoteView } from "@blocknote/mantine";
import {
  DragHandleButton,
  SideMenu,
  SideMenuController,
  useCreateBlockNote,
} from "@blocknote/react";
import { useTheme } from "next-themes";
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

  // BlockNote ships its own light/dark themes; bind to the same `next-themes`
  // resolution the rest of the app uses so the editor follows the toggle and
  // the OS preference. `resolvedTheme` is undefined for one render before
  // next-themes hydrates — fall back to "light" so we don't flash the dark
  // theme on a light-mode page.
  const { resolvedTheme } = useTheme();
  const editorTheme = resolvedTheme === "dark" ? "dark" : "light";

  return (
    <div
      id={id}
      aria-label={ariaLabel}
      // BlockNote ships with `padding-inline: 54px` on `.bn-editor` to leave
      // room for its side menu and drag handle. We render only the drag
      // handle (and only on hover), so most of that gutter is wasted —
      // override it to a tighter, symmetric value scoped to this instance.
      //
      // Background is fully transparent at every layer (our wrapper, the
      // .bn-mantine container, and the .bn-editor itself) so the editor
      // picks up whatever Card/page surface it sits on. Without these
      // overrides Mantine paints `--mantine-color-body` underneath in
      // dark mode, which shows up as a lighter band around the cursor.
      //
      // The drag handle's pill background is normally only painted on
      // hover. Force the hovered colour on by default so the dots are
      // always visible and easier to grab once they appear.
      className="block w-full border border-input rounded-md focus-within:ring-2 focus-within:ring-ring min-h-24 py-1 [&_.bn-editor]:!px-3 [&_.bn-mantine]:!bg-transparent [&_.bn-editor]:!bg-transparent [&_.bn-side-menu_button]:!bg-[var(--bn-colors-hovered-background)]"
    >
      <BlockNoteView
        editor={editor}
        theme={editorTheme}
        sideMenu={false}
        onChange={() => {
          onChange(editor.blocksToMarkdownLossy(editor.document));
        }}
      >
        {/* Replace BlockNote's default side menu (drag handle + add-block "+")
            with one that only renders the drag handle. Pressing Enter already
            inserts a new block, so the "+" was just eating the small gutter
            on narrow forms. */}
        <SideMenuController
          sideMenu={(props) => (
            <SideMenu {...props}>
              <DragHandleButton {...props} />
            </SideMenu>
          )}
        />
      </BlockNoteView>
    </div>
  );
};

export default MarkdownEditorInner;
