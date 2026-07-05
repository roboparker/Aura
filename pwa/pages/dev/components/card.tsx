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
    ]}
  />
);

export default CardPage;
