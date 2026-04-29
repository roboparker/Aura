import { useRef, useState } from "react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { useAuth } from "@/contexts/AuthContext";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { uploadAvatar } from "@/lib/uploadAvatar";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

const ACCEPTED = "image/jpeg,image/png,image/webp,image/gif";
const MAX_BYTES = 5 * 1024 * 1024;

const AvatarSection = () => {
  const { user, refreshUser, updateUserLocally } = useAuth();
  const fileInput = useRef<HTMLInputElement>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [colorError, setColorError] = useState<string | null>(null);
  const [savingColor, setSavingColor] = useState<string | null>(null);

  if (!user) return null;

  const onPick = () => {
    setError(null);
    fileInput.current?.click();
  };

  const onChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;

    if (file.size > MAX_BYTES) {
      setError("File is larger than 5 MB.");
      return;
    }

    setIsUploading(true);
    setError(null);
    try {
      await uploadAvatar(file, user.id);
      await refreshUser();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Upload failed.");
    } finally {
      setIsUploading(false);
    }
  };

  const onPickColor = async (color: string) => {
    if (color === user.personalizedColor || savingColor) return;
    const previousColor = user.personalizedColor;
    setColorError(null);
    setSavingColor(color);
    updateUserLocally({ personalizedColor: color });
    try {
      const res = await fetch(`${ENTRYPOINT}/users/${user.id}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ personalizedColor: color }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.detail || "Failed to update avatar color.");
      }
      await refreshUser();
    } catch (err) {
      // Roll back the optimistic update so the UI matches the server again.
      updateUserLocally({ personalizedColor: previousColor });
      setColorError(
        err instanceof Error ? err.message : "Failed to update avatar color.",
      );
    } finally {
      setSavingColor(null);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-4">
        <UserAvatar user={user} size="lg" />
        <div>
          <Button type="button" onClick={onPick} disabled={isUploading} size="sm">
            {isUploading ? "Uploading..." : "Change avatar"}
          </Button>
          <input
            ref={fileInput}
            type="file"
            accept={ACCEPTED}
            onChange={onChange}
            className="hidden"
          />
          {error && <p className="mt-2 text-sm text-destructive">{error}</p>}
          <p className="mt-2 text-xs text-muted-foreground">
            JPEG, PNG, WebP, or GIF. Max 5 MB.
          </p>
        </div>
      </div>

      <fieldset className="space-y-1.5">
        <legend className="text-sm font-medium leading-none">
          Avatar color{" "}
          <span className="text-muted-foreground font-normal">
            (used when you have no picture)
          </span>
        </legend>
        <div
          role="radiogroup"
          aria-label="Avatar color"
          className="flex flex-wrap gap-2"
          data-testid="avatar-color-palette"
        >
          {AVATAR_PALETTE.map((color) => {
            const isSelected = user.personalizedColor === color;
            const isSaving = savingColor === color;
            return (
              <button
                key={color}
                type="button"
                role="radio"
                aria-checked={isSelected}
                aria-label={color}
                disabled={!!savingColor}
                onClick={() => onPickColor(color)}
                className={cn(
                  "h-8 w-8 rounded-full transition focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ring disabled:cursor-not-allowed",
                  isSelected
                    ? "ring-2 ring-offset-2 ring-ring scale-110"
                    : "hover:scale-105",
                  isSaving && "animate-pulse",
                )}
                style={{ backgroundColor: color }}
              />
            );
          })}
        </div>
        {colorError && (
          <p role="alert" className="text-sm text-destructive">
            {colorError}
          </p>
        )}
      </fieldset>
    </div>
  );
};

export default AvatarSection;
