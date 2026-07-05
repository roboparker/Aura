import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import ComponentDoc from "@/components/dev/ComponentDoc";

const DialogPage = () => (
  <ComponentDoc
    name="Dialog"
    description="Modal dialog built on radix-ui. Use Sheet for side-anchored panels."
    importPath={`import { Dialog, DialogTrigger, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter, DialogClose } from "@/components/ui/dialog";`}
    examples={[
      {
        title: "Confirm action",
        code: `<Dialog>
  <DialogTrigger asChild>
    <Button variant="outline">Delete board</Button>
  </DialogTrigger>
  <DialogContent>
    <DialogHeader>
      <DialogTitle>Delete this board?</DialogTitle>
      <DialogDescription>
        This permanently removes the board and all its tasks.
      </DialogDescription>
    </DialogHeader>
    <DialogFooter>
      <DialogClose asChild>
        <Button variant="outline">Cancel</Button>
      </DialogClose>
      <Button variant="destructive">Delete</Button>
    </DialogFooter>
  </DialogContent>
</Dialog>`,
        preview: (
          <Dialog>
            <DialogTrigger asChild>
              <Button variant="outline">Delete board</Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>Delete this board?</DialogTitle>
                <DialogDescription>
                  This permanently removes the board and all its tasks.
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <DialogClose asChild>
                  <Button variant="outline">Cancel</Button>
                </DialogClose>
                <Button variant="destructive">Delete</Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        ),
      },
    ]}
  />
);

export default DialogPage;
