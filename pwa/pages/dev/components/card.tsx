import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import ComponentDoc from "@/components/dev/ComponentDoc";

const CardPage = () => (
  <ComponentDoc
    name="Card"
    description="Surface container with optional header, content, and footer slots."
    importPath={`import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from "@/components/ui/card";`}
    examples={[
      {
        title: "With header, content, and footer",
        code: `<Card className="max-w-sm">
  <CardHeader>
    <CardTitle>Board moonshot</CardTitle>
    <CardDescription>Q3 launch readiness</CardDescription>
  </CardHeader>
  <CardContent>
    <p className="text-sm text-muted-foreground">
      Track tasks, owners, and timelines from one place.
    </p>
  </CardContent>
  <CardFooter className="justify-end">
    <Button size="sm">Open</Button>
  </CardFooter>
</Card>`,
        preview: (
          <Card className="max-w-sm">
            <CardHeader>
              <CardTitle>Board moonshot</CardTitle>
              <CardDescription>Q3 launch readiness</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground">
                Track tasks, owners, and timelines from one place.
              </p>
            </CardContent>
            <CardFooter className="justify-end">
              <Button size="sm">Open</Button>
            </CardFooter>
          </Card>
        ),
      },
      {
        title: "Heading level (`as`)",
        code: `{/* Default — h2. Correct when the card is a top-level
    section beneath the page's h1. */}
<CardTitle>Section title</CardTitle>

{/* The card *is* the page (auth screens): its title is the h1. */}
<CardTitle as="h1">Welcome back</CardTitle>

{/* Nested under another heading. */}
<CardTitle as="h3">Nested subsection</CardTitle>

{/* Decorative only — keep it out of the outline. */}
<CardTitle as="div">Not a heading</CardTitle>`,
        preview: (
          <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
              CardTitle renders an <code>h2</code> by default — card titles are headings, not
              styled text, and heading navigation is how screen-reader users skim a page. The
              styling is class-driven, so changing the level is visually inert. Pick the level
              that keeps the page outline contiguous; don&apos;t skip levels.
            </p>
            <Card className="max-w-sm">
              <CardHeader>
                <CardTitle>Default (h2)</CardTitle>
                <CardDescription>Top-level section under the page h1</CardDescription>
              </CardHeader>
            </Card>
            <Card className="max-w-sm">
              <CardHeader>
                <CardTitle as="h3">as=&quot;h3&quot;</CardTitle>
                <CardDescription>Nested under another heading</CardDescription>
              </CardHeader>
            </Card>
          </div>
        ),
      },
    ]}
  />
);

export default CardPage;
