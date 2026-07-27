import { toast } from "sonner";

import ComponentDoc from "@/components/dev/ComponentDoc";
import { Button } from "@/components/ui/button";

const SonnerPage = () => (
  <ComponentDoc
    name="Toaster (sonner)"
    description="App-wide transient notifications. Mounted once in `_app.tsx`; call the `toast()` function from anywhere. Theme is pinned to dark and the palette is overridden to the app's design tokens, so toasts match cards and dialogs. Use a toast to confirm an action the user just took — especially one that offers Undo. It is not a replacement for inline form errors, which must stay next to the field they describe."
    importPath={`import { toast } from "sonner";`}
    examples={[
      {
        title: "Undoable action",
        code: `toast("Deleted “Draft onboarding email”", {
  duration: 7000,
  action: { label: "Undo", onClick: () => restore() },
})`,
        preview: (
          <Button
            variant="outline"
            onClick={() =>
              toast("Deleted “Draft onboarding email”", {
                duration: 7000,
                action: { label: "Undo", onClick: () => toast.success("Restored") },
              })
            }
          >
            Show undo toast
          </Button>
        ),
      },
      {
        title: "Status variants",
        code: `toast.success("Saved")
toast.error("Could not reach the server")
toast("Plain message")`,
        preview: (
          <div className="flex flex-wrap gap-3">
            <Button variant="outline" onClick={() => toast.success("Saved")}>
              Success
            </Button>
            <Button variant="outline" onClick={() => toast.error("Could not reach the server")}>
              Error
            </Button>
            <Button variant="outline" onClick={() => toast("Plain message")}>
              Plain
            </Button>
          </div>
        ),
      },
      {
        title: "With a description",
        code: `toast("Export started", {
  description: "We'll email you a link when it's ready.",
})`,
        preview: (
          <Button
            variant="outline"
            onClick={() =>
              toast("Export started", {
                description: "We'll email you a link when it's ready.",
              })
            }
          >
            Show
          </Button>
        ),
      },
    ]}
  />
);

export default SonnerPage;
