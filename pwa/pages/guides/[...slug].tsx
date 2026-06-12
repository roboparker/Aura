import { Children, isValidElement, type ReactElement } from "react";
import type { GetStaticPaths, GetStaticProps } from "next";
import Head from "next/head";
import Link from "next/link";
import ReactMarkdown, { type Components } from "react-markdown";
import remarkGfm from "remark-gfm";
import { ChevronLeft } from "lucide-react";
import { Button } from "@/components/ui/button";
import CodeFence from "@/components/editor/CodeFence";
import { getAllDocs, loadDoc, type LoadedDoc } from "@/lib/docs";

// `<pre>` override: react-markdown wraps a fenced code block as
// `<pre><code className="language-xxx">{code}</code></pre>`. Unwrap it
// to extract the language + raw source and hand off to CodeFence, which
// runs Prism. Plain `<pre>` (no language) falls back to default styling.
const renderPre: Components["pre"] = ({ children }) => {
  const only = Children.toArray(children).find(isValidElement) as
    | ReactElement<{ className?: string; children?: unknown }>
    | undefined;
  const className = only?.props.className ?? "";
  const match = /language-([\w+-]+)/.exec(className);
  if (only && match) {
    const code = String(only.props.children ?? "").replace(/\n$/, "");
    return <CodeFence code={code} language={match[1]} />;
  }
  return <pre>{children}</pre>;
};

const MARKDOWN_COMPONENTS: Components = { pre: renderPre };

interface Props {
  doc: LoadedDoc;
  sectionLabel: string;
}

const SECTION_LABELS: Record<string, string> = {
  developer: "Developer",
  user: "User",
};

const GuidePage = ({ doc, sectionLabel }: Props) => (
  <>
    <Head>
      <title>{`${doc.title} — Madori guides`}</title>
      <meta
        name="description"
        content={`${doc.title} — ${sectionLabel} documentation for Madori.`}
      />
    </Head>
    <main className="bg-background">
      <div className="mx-auto max-w-3xl px-6 py-10 md:py-16">
        <Button asChild variant="ghost" size="sm" className="mb-6 -ml-2 gap-1">
          <Link href="/guides">
            <ChevronLeft className="h-4 w-4" aria-hidden />
            All guides
          </Link>
        </Button>

        <header className="mb-8 border-b pb-6">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">
            {sectionLabel}
          </p>
          <h1 className="mt-1 text-3xl md:text-4xl font-bold tracking-tight">
            {doc.title}
          </h1>
        </header>

        <article className="doc-content">
          <ReactMarkdown
            remarkPlugins={[remarkGfm]}
            components={MARKDOWN_COMPONENTS}
          >
            {doc.body}
          </ReactMarkdown>
        </article>
      </div>
    </main>
  </>
);

export const getStaticPaths: GetStaticPaths = async () => {
  const docs = await getAllDocs();
  return {
    paths: docs.map((d) => ({ params: { slug: d.slug } })),
    fallback: false,
  };
};

export const getStaticProps: GetStaticProps<Props> = async (context) => {
  const raw = context.params?.slug;
  const slug = Array.isArray(raw) ? raw : raw ? [raw] : [];
  const doc = await loadDoc(slug);
  if (!doc) return { notFound: true };
  return {
    props: {
      doc,
      sectionLabel: SECTION_LABELS[doc.section] ?? doc.section,
    },
  };
};

export default GuidePage;
