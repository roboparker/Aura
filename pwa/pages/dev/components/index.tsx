import Head from "next/head";
import Link from "next/link";
import { Card, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { componentRegistry } from "@/components/dev/registry";

const categories = [
  "Primitive",
  "Form",
  "Overlay",
  "Data",
  "Editor",
  "User",
  "Layout",
  "Discussions",
  "Custom fields",
  "Groups",
  "Notifications",
] as const;

const ComponentsIndex = () => (
  <>
    <Head>
      <title>Components · Dev</title>
    </Head>
    <main className="mx-auto max-w-5xl px-6 py-10">
      <header className="mb-8">
        <h1 className="text-3xl font-semibold tracking-tight">Component library</h1>
        <p className="mt-2 max-w-2xl text-muted-foreground">
          Living documentation for every reusable component in the PWA. Each page shows live
          examples alongside the source you can copy into your own code.
        </p>
      </header>
      {categories.map((category) => {
        const entries = componentRegistry.filter((c) => c.category === category);
        if (entries.length === 0) return null;
        return (
          <section key={category} className="mb-10">
            <div className="mb-3 flex items-baseline gap-3">
              <h2 className="text-xl font-medium">{category}</h2>
              <Badge variant="secondary">{entries.length}</Badge>
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {entries.map((entry) => (
                <Link key={entry.slug} href={`/dev/components/${entry.slug}`}>
                  <Card className="h-full transition-colors hover:border-foreground/40">
                    <CardHeader>
                      <CardTitle className="text-base">{entry.name}</CardTitle>
                      <CardDescription>{entry.description}</CardDescription>
                    </CardHeader>
                  </Card>
                </Link>
              ))}
            </div>
          </section>
        );
      })}
    </main>
  </>
);

export default ComponentsIndex;
