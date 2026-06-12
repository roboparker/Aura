import type { GetStaticProps } from "next";
import Head from "next/head";
import Link from "next/link";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { getDocSections, type DocSection } from "@/lib/docs";

interface Props {
  sections: DocSection[];
}

const GuidesIndex = ({ sections }: Props) => (
  <>
    <Head>
      <title>Guides — Madori</title>
      <meta
        name="description"
        content="User and developer documentation for Madori."
      />
    </Head>
    <main className="bg-background">
      <div className="mx-auto max-w-5xl px-6 py-10 md:py-16">
        <header className="mb-10">
          <h1 className="text-3xl md:text-4xl font-bold tracking-tight">
            Guides
          </h1>
          <p className="mt-2 max-w-2xl text-muted-foreground">
            User-facing how-tos and developer reference, rendered from the
            project&apos;s markdown docs.
          </p>
        </header>

        {sections.length === 0 ? (
          <p className="text-muted-foreground">
            No documentation pages have been published yet.
          </p>
        ) : (
          sections.map((section) => (
            <section key={section.slug} className="mb-10">
              <div className="mb-3 flex items-baseline gap-3">
                <h2 className="text-xl font-medium">{section.title}</h2>
                <Badge variant="secondary">{section.entries.length}</Badge>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                {section.entries.map((entry) => (
                  <Link
                    key={entry.href}
                    href={entry.href}
                    className="no-underline"
                  >
                    <Card className="h-full transition-colors hover:border-primary">
                      <CardHeader>
                        <CardTitle className="text-base">
                          {entry.title}
                        </CardTitle>
                        <CardDescription className="font-mono text-xs">
                          {entry.slug.join("/")}.md
                        </CardDescription>
                      </CardHeader>
                    </Card>
                  </Link>
                ))}
              </div>
            </section>
          ))
        )}
      </div>
    </main>
  </>
);

export const getStaticProps: GetStaticProps<Props> = async () => ({
  props: { sections: await getDocSections() },
});

export default GuidesIndex;
