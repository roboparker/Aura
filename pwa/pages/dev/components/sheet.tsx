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
      {
        title: "Other sides",
        code: `{(["left", "top", "bottom"] as const).map((side) => (
  <Sheet key={side}>
    <SheetTrigger asChild>
      <Button variant="outline">Open {side}</Button>
    </SheetTrigger>
    <SheetContent side={side}>
      <SheetHeader>
        <SheetTitle>{side} drawer</SheetTitle>
        <SheetDescription>Anchored to the {side} edge.</SheetDescription>
      </SheetHeader>
    </SheetContent>
  </Sheet>
))}`,
        preview: (
          <div className="flex flex-wrap gap-2">
            {(["left", "top", "bottom"] as const).map((side) => (
              <Sheet key={side}>
                <SheetTrigger asChild>
                  <Button variant="outline">Open {side}</Button>
                </SheetTrigger>
                <SheetContent side={side}>
                  <SheetHeader>
                    <SheetTitle>{side} drawer</SheetTitle>
                    <SheetDescription>Anchored to the {side} edge.</SheetDescription>
                  </SheetHeader>
                </SheetContent>
              </Sheet>
            ))}
          </div>
        ),
      },
      {
        title: "Without close button",
        code: `// Pass showCloseButton={false} when the drawer supplies its own
// dismissal (e.g. a non-modal drawer with footer actions).
<Sheet>
  <SheetTrigger asChild>
    <Button variant="outline">Open</Button>
  </SheetTrigger>
  <SheetContent side="right" showCloseButton={false}>
    <SheetHeader>
      <SheetTitle>No X in the corner</SheetTitle>
      <SheetDescription>Dismiss via the overlay or your own button.</SheetDescription>
    </SheetHeader>
  </SheetContent>
</Sheet>`,
        preview: (
          <Sheet>
            <SheetTrigger asChild>
              <Button variant="outline">Open</Button>
            </SheetTrigger>
            <SheetContent side="right" showCloseButton={false}>
              <SheetHeader>
                <SheetTitle>No X in the corner</SheetTitle>
                <SheetDescription>Dismiss via the overlay or your own button.</SheetDescription>
              </SheetHeader>
            </SheetContent>
          </Sheet>
        ),
      },
    ]}
  />
);

export default SheetPage;
