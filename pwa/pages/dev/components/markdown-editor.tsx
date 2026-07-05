import { useState } from "react";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import ComponentDoc from "@/components/dev/ComponentDoc";

const Demo = () => {
  const [value, setValue] = useState("# Hello\n\nType **markdown** here. Drag-and-drop, slash commands, and inline formatting all work.");
  return <MarkdownEditor value={value} onChange={setValue} ariaLabel="Demo editor" />;
};

const MarkdownEditorPage = () => (
  <ComponentDoc
    name="MarkdownEditor"
    description="BlockNote-backed WYSIWYG editor that reads and writes markdown. Lazy-loaded on the client because BlockNote touches the DOM at import time."
    importPath={`import MarkdownEditor from "@/components/editor/MarkdownEditor";`}
    examples={[
      {
        title: "Controlled value",
        code: `const [value, setValue] = useState("# Hello\\n\\nType markdown here.");

<MarkdownEditor
  value={value}
  onChange={setValue}
  ariaLabel="Board description"
/>`,
        preview: <div className="w-full"><Demo /></div>,
      },
    ]}
  />
);

export default MarkdownEditorPage;
