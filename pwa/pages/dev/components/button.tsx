import { Plus, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import ComponentDoc from "@/components/dev/ComponentDoc";

const ButtonPage = () => (
  <ComponentDoc
    name="Button"
    description="Variants and sizes built on class-variance-authority. Pass `asChild` to render the styles on an inner element (e.g. <Link>)."
    importPath={`import { Button } from "@/components/ui/button";`}
    examples={[
      {
        title: "Variants",
        code: `<Button>Default</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="ghost">Ghost</Button>
<Button variant="destructive">Destructive</Button>
<Button variant="link">Link</Button>`,
        preview: (
          <div className="flex flex-wrap gap-2">
            <Button>Default</Button>
            <Button variant="secondary">Secondary</Button>
            <Button variant="outline">Outline</Button>
            <Button variant="ghost">Ghost</Button>
            <Button variant="destructive">Destructive</Button>
            <Button variant="link">Link</Button>
          </div>
        ),
      },
      {
        title: "Sizes",
        code: `<Button size="sm">Small</Button>
<Button>Default</Button>
<Button size="lg">Large</Button>
<Button size="icon" aria-label="Add"><Plus /></Button>
{/* icon-xs (h-5 w-5) — used internally by Combobox/InputGroup for inline trigger + chip-remove buttons */}
<Button size="icon-xs" variant="ghost" aria-label="Remove"><X /></Button>`,
        preview: (
          <div className="flex flex-wrap items-center gap-2">
            <Button size="sm">Small</Button>
            <Button>Default</Button>
            <Button size="lg">Large</Button>
            <Button size="icon" aria-label="Add"><Plus /></Button>
            <Button size="icon-xs" variant="ghost" aria-label="Remove"><X /></Button>
          </div>
        ),
      },
      {
        title: "Disabled",
        code: `<Button disabled>Saving…</Button>`,
        preview: <Button disabled>Saving…</Button>,
      },
      {
        title: "Loading (`loading`)",
        code: `{/* Sets aria-busy AND disables, so the pending state is exposed
    programmatically rather than only via the swapped label.
    You keep owning the label — this is semantics, not copy. */}
<Button loading>Signing in…</Button>`,
        preview: (
          <div className="space-y-2">
            <Button loading>Signing in…</Button>
            <p className="text-sm text-muted-foreground">
              A <code>disabled</code> button says it can&apos;t be used;{" "}
              <code>loading</code> says why. Passing <code>disabled</code>{" "}
              explicitly still wins, so an already-invalid form stays disabled
              without claiming to be busy.
            </p>
          </div>
        ),
      },
      {
        title: "Pressed state",
        code: `{/* Every variant has an active: step. Press and hold to see it. */}
<Button>Default</Button>
<Button variant="secondary">Secondary</Button>
<Button variant="outline">Outline</Button>
<Button variant="destructive">Destructive</Button>`,
        preview: (
          <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
              <Button>Default</Button>
              <Button variant="secondary">Secondary</Button>
              <Button variant="outline">Outline</Button>
              <Button variant="destructive">Destructive</Button>
              <Button variant="ghost">Ghost</Button>
            </div>
            <p className="text-sm text-muted-foreground">
              On touch there is no hover, so the pressed state is the{" "}
              <em>only</em> confirmation a tap registered — without it users
              re-tap, which is what turns a slow request into a double submit.
              The colour step carries the signal; the{" "}
              <code>scale-[.98]</code> is suppressed under{" "}
              <code>prefers-reduced-motion</code>.
            </p>
          </div>
        ),
      },
    ]}
  />
);

export default ButtonPage;
