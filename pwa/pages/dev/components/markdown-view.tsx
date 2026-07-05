import MarkdownView from "@/components/editor/MarkdownView";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SAMPLE = `# Board moonshot

A short summary of the **goal**, with a [link](https://example.com) and a list:

- Tasks
- Owners
- Deadlines

> Quote a stakeholder for context.

\`\`\`ts
const ready = true;
\`\`\``;

const MarkdownViewPage = () => (
  <ComponentDoc
    name="MarkdownView"
    description="Read-only markdown renderer (react-markdown + remark-gfm). Paired with MarkdownEditor for the same content. Raw HTML is escaped."
    importPath={`import MarkdownView from "@/components/editor/MarkdownView";`}
    examples={[
      {
        title: "Render stored markdown",
        code: `<MarkdownView source={board.description} />`,
        preview: <MarkdownView source={SAMPLE} />,
      },
      {
        title: "Highlight @mention chips (comment view)",
        code: `<MarkdownView source={comment.body} highlightMentions />`,
        preview: (
          <MarkdownView
            source={"Heads up @bob — please look. cc @carol"}
            highlightMentions
          />
        ),
      },
    ]}
  />
);

export default MarkdownViewPage;
