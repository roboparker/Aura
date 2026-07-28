import Head from "next/head";
import Link from "next/link";
import type { ReactNode } from "react";
import { ArrowLeft } from "lucide-react";
import { Separator } from "@/components/ui/separator";
import CodeBlock from "./CodeBlock";

type Example = {
  title?: string;
  description?: string;
  code: string;
  preview: ReactNode;
};

type ComponentDocProps = {
  name: string;
  description: string;
  importPath: string;
  examples: Example[];
};

const ComponentDoc = ({ name, description, importPath, examples }: ComponentDocProps) => (
  <>
    <Head>
      <title>{`${name} · Dev Components`}</title>
    </Head>
    <div className="mx-auto max-w-4xl px-6 py-10">
      <Link
        href="/dev/components"
        className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-3.5 w-3.5" />
        All components
      </Link>
      <header className="mt-4 mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">{name}</h1>
        <p className="mt-2 text-muted-foreground">{description}</p>
        <code className="mt-3 inline-block rounded-sm bg-muted px-2 py-1 font-mono text-xs">
          {importPath}
        </code>
      </header>
      <Separator className="mb-8" />
      <div className="space-y-10">
        {examples.map((example, i) => (
          <section key={i}>
            {example.title && <h2 className="mb-1 text-lg font-medium">{example.title}</h2>}
            {example.description && (
              <p className="mb-4 text-sm text-muted-foreground">{example.description}</p>
            )}
            <div className="rounded-md border border-input bg-background p-6">
              {example.preview}
            </div>
            <CodeBlock className="mt-3" code={example.code} />
          </section>
        ))}
      </div>
    </div>
  </>
);

export default ComponentDoc;
