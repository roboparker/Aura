import { FormEvent, useState } from "react";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

/**
 * Controlled "+ New space" dialog. Wraps the existing POST /spaces
 * flow that used to live inline on `/spaces` — the page now ships
 * just a trigger button in its header and hands all state to this
 * component.
 *
 * On success the parent's space list is refreshed via
 * {@see ActiveSpaceContext.refresh()} so the new card slots into the
 * grid without an extra fetch round-trip on the page side.
 */
interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const CreateSpaceDialog = ({ open, onOpenChange }: Props) => {
  const { refresh } = useActiveSpace();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const reset = () => {
    setName("");
    setDescription("");
    setError(null);
  };

  const handleClose = (next: boolean) => {
    if (!next) reset();
    onOpenChange(next);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!name.trim()) return;
    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/spaces`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to create space.",
        );
      }
      await refresh();
      handleClose(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create space.");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>New space</DialogTitle>
          <DialogDescription>
            A space holds related projects, discussions, and pages. You can
            invite people after it&apos;s created.
          </DialogDescription>
        </DialogHeader>

        <form
          onSubmit={handleSubmit}
          className="space-y-4"
          data-testid="create-space-form"
        >
          <div className="space-y-1.5">
            <Label htmlFor="new-space-name">Name</Label>
            <Input
              id="new-space-name"
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              maxLength={255}
              placeholder="Backend team"
              autoFocus
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="new-space-description">
              Description{" "}
              <span className="text-muted-foreground font-normal">
                (optional)
              </span>
            </Label>
            <Textarea
              id="new-space-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              maxLength={500}
              rows={3}
              placeholder="What goes in here?"
            />
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={() => handleClose(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={isSubmitting || !name.trim()}>
              {isSubmitting ? "Creating…" : "Create space"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

export default CreateSpaceDialog;
