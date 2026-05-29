import { useState } from "react";
import { RotateCcw } from "lucide-react";
import type { Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import AvatarColorPicker from "@/components/auth/AvatarColorPicker";
import SpaceTile from "./SpaceTile";

/**
 * Admin-only color override for a Space. The picker pre-selects the
 * effective color via {@see resolveSpaceColor} so the radio state
 * tracks what users actually see — explicit override if set, otherwise
 * the creator's `personalizedColor`.
 *
 * "Reset to default" PATCHes `color: null`; tapping a swatch PATCHes
 * the hex. Either way, on success the parent's `onSaved` callback
 * re-fetches so the new color shows up across the navbar tile, the
 * spaces grid, and the sidebar list.
 */
interface Props {
  space: Space;
  onSaved: () => Promise<void> | void;
}

const SpaceColorPicker = ({ space, onSaved }: Props) => {
  const effective = resolveSpaceColor(space);
  const [draft, setDraft] = useState<string | null>(space.color);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const draftEffective = draft ?? space.createdBy?.personalizedColor ?? effective;
  const dirty = draft !== space.color;

  const persist = async (next: string | null) => {
    setIsSaving(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ color: next }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(
          body.detail || body["hydra:description"] || "Failed to save color.",
        );
      }
      setDraft(next);
      await onSaved();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save color.");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Card>
      <CardContent className="pt-6 space-y-4">
        <div className="flex items-center gap-3">
          <SpaceTile
            name={space.name}
            color={draftEffective}
            isPersonal={space.isPersonal}
            size="lg"
          />
          <div className="min-w-0">
            <Label className="text-sm">Tile color</Label>
            <p className="text-xs text-muted-foreground mt-1">
              {space.color
                ? "Custom color set."
                : "Inheriting from the creator's avatar color."}
            </p>
          </div>
        </div>

        <AvatarColorPicker
          initials={(space.name.trim()[0] ?? "?").toUpperCase()}
          selected={draftEffective}
          onChange={(color) => setDraft(color)}
        />

        {error && (
          <Alert variant="destructive">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        <div className="flex items-center justify-end gap-2">
          {space.color !== null && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="gap-1.5"
              disabled={isSaving}
              onClick={() => persist(null)}
            >
              <RotateCcw className="h-3.5 w-3.5" />
              Reset to default
            </Button>
          )}
          <Button
            type="button"
            size="sm"
            disabled={isSaving || !dirty}
            onClick={() => persist(draft)}
          >
            {isSaving ? "Saving…" : "Save color"}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
};

export default SpaceColorPicker;
