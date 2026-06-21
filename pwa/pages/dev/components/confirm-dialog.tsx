import { useState } from "react";
import ComponentDoc from "@/components/dev/ComponentDoc";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import { Button } from "@/components/ui/button";

const Basic = () => {
  const [open, setOpen] = useState(false);
  const [done, setDone] = useState(false);
  return (
    <div className="space-y-2">
      <Button
        variant="destructive"
        size="sm"
        onClick={() => {
          setDone(false);
          setOpen(true);
        }}
      >
        Delete item
      </Button>
      {done && <p className="text-sm text-muted-foreground">Item deleted.</p>}
      <ConfirmDialog
        open={open}
        onOpenChange={setOpen}
        title="Delete item"
        description="This can’t be undone."
        confirmLabel="Delete"
        destructive
        onConfirm={async () => {
          // Simulate an async request; throw here to keep the dialog open and
          // show an inline error instead.
          await new Promise((resolve) => setTimeout(resolve, 600));
          setDone(true);
        }}
      />
    </div>
  );
};

const ConfirmDialogPage = () => (
  <ComponentDoc
    name="ConfirmDialog"
    description="Shared confirmation modal — the replacement for scattered `window.confirm` calls. Controlled via `open` / `onOpenChange`, it owns the async confirm lifecycle: the confirm button shows a spinner while `onConfirm` runs, an `onConfirm` that throws keeps the dialog open and surfaces the error inline, and a resolved `onConfirm` closes it. While the action is in flight the dialog can't be dismissed (Escape / outside-click / X ignored). Pass `destructive` to style the confirm button red."
    importPath={`import ConfirmDialog from "@/components/common/ConfirmDialog";`}
    examples={[
      {
        title: "Destructive confirm with an async action",
        description:
          "The confirm button spins while onConfirm runs and closes on success. Have onConfirm throw to keep it open with an inline error.",
        code: `const [open, setOpen] = useState(false);

<Button variant="destructive" onClick={() => setOpen(true)}>
  Delete item
</Button>

<ConfirmDialog
  open={open}
  onOpenChange={setOpen}
  title="Delete item"
  description="This can’t be undone."
  confirmLabel="Delete"
  destructive
  onConfirm={async () => {
    await api.delete(id); // throw to keep the dialog open + show the error
  }}
/>`,
        preview: <Basic />,
      },
    ]}
  />
);

export default ConfirmDialogPage;
