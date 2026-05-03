import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import ComponentDoc from "@/components/dev/ComponentDoc";

const SheetPage = () => (
  <ComponentDoc
    name="Sheet"
    description="Side-anchored drawer built on radix-ui Dialog. Pick `side` to anchor it to right, left, top, or bottom."
    importPath={`import { Sheet, SheetTrigger, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@/components/ui/sheet";`}
    examples={[
      {
        title: "Right drawer",
        code: `<Sheet>
  <SheetTrigger asChild>
    <Button variant="outline">Open settings</Button>
  </SheetTrigger>
  <SheetContent side="right">
    <SheetHeader>
      <SheetTitle>Settings</SheetTitle>
      <SheetDescription>Personal preferences sync across devices.</SheetDescription>
    </SheetHeader>
  </SheetContent>
</Sheet>`,
        preview: (
          <Sheet>
            <SheetTrigger asChild>
              <Button variant="outline">Open settings</Button>
            </SheetTrigger>
            <SheetContent side="right">
              <SheetHeader>
                <SheetTitle>Settings</SheetTitle>
                <SheetDescription>Personal preferences sync across devices.</SheetDescription>
              </SheetHeader>
            </SheetContent>
          </Sheet>
        ),
      },
    ]}
  />
);

export default SheetPage;
