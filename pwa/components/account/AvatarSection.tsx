import { useRef, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { uploadAvatar } from "@/lib/uploadAvatar";
import UserAvatar from "@/components/user/UserAvatar";
import { Button } from "@/components/ui/button";

const ACCEPTED = "image/jpeg,image/png,image/webp,image/gif";
const MAX_BYTES = 5 * 1024 * 1024;

const AvatarSection = () => {
  const { user, refreshUser } = useAuth();
  const fileInput = useRef<HTMLInputElement>(null);
  const [isUploading, setIsUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  return (
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
  );
};

export default AvatarSection;
