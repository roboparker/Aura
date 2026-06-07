import CodeFence from "@/components/editor/CodeFence";
import ComponentDoc from "@/components/dev/ComponentDoc";

const TS_SAMPLE = `export function greet(name: string): string {
  return \`Hello, \${name}!\`;
}`;

const CodeFencePage = () => (
  <ComponentDoc
    name="CodeFence"
    description="Syntax-highlighted code block (prism-react-renderer) with a copy button and a per-page CodeThemeSwitcher in the header bar. `language` is normalised (ts→typescript, sh→bash, etc.); an unknown language falls through to plain monospace rather than throwing. Every preset's palette is rendered up-front and CSS shows only the active one, so a saved theme paints with no flash on load. Used to render fenced code in the guides."
    importPath={`import CodeFence from "@/components/editor/CodeFence";`}
    examples={[
      {
        title: "TypeScript",
        code: `<CodeFence language="ts" code={tsSource} />`,
        preview: <CodeFence language="ts" code={TS_SAMPLE} />,
      },
      {
        title: "Unknown language (plain fallback)",
        description:
          "A language outside Prism's set renders as plain monospace — the header still shows the label and copy button.",
        code: `<CodeFence language="brainfuck" code="++++++++[>++++++++<-]>+." />`,
        preview: (
          <CodeFence language="brainfuck" code="++++++++[>++++++++<-]>+." />
        ),
      },
    ]}
  />
);

export default CodeFencePage;
