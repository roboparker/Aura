import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Info } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { trackEvent } from "@/lib/analytics";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import EmailChipInput from "@/components/common/EmailChipInput";
import GroupTile from "@/components/groups/GroupTile";

const MAX_INVITES = 50;

interface CreatedGroup {
  "@id": string;
  id: string;
}

/**
 * Full-page Create Group form, mirroring `/spaces/new`. Groups have no
 * visibility toggle (they're always private to their members), so the
 * form is name + description + color + initial invites. The group is
 * POSTed first, then each invite is resolved server-side via
 * `POST /groups/{id}/members` (the email → user lookup lives there).
 */
const NewGroupPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const spaceIri = activeSpace?.["@id"] ?? null;

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState<string>(
    () => user?.personalizedColor ?? AVATAR_PALETTE[0],
  );
  const [invites, setInvites] = useState<string[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (user?.personalizedColor) setColor(user.personalizedColor);
  }, [user?.personalizedColor]);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const goBack = useCallback(() => {
    if (window.history.length > 1) {
      router.back();
    } else {
      router.push("/groups");
    }
  }, [router]);

  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === "Escape") {
        e.preventDefault();
        goBack();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [goBack]);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;
    if (!spaceIri) {
      setError("Select a space first.");
      return;
    }

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
          color,
          space: spaceIri,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to create group.",
        );
      }
      const group: CreatedGroup = await res.json();
      trackEvent("group-create");

      // Fan out invites. Existing users join immediately; unknown emails
      // get an email invite. Best-effort — a failed invite doesn't undo
      // the group, so we surface it but still navigate.
      const failed: string[] = [];
      for (const email of invites) {
        const inviteRes = await fetch(
          `${ENTRYPOINT}/groups/${group.id}/members`,
          {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ email }),
          },
        );
        if (!inviteRes.ok) failed.push(email);
      }
      if (failed.length > 0) {
        // Stash a note for the detail page via query so the owner sees it.
        await router.push(
          `/groups/${group.id}?inviteError=${encodeURIComponent(failed.join(","))}`,
        );
        return;
      }

      await router.push(`/groups/${group.id}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create group.");
      setIsSubmitting(false);
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const inviteCount = invites.length;
  const inviteSummary =
    inviteCount === 0
      ? "Add teammates now or later (optional)"
      : `${inviteCount} ${inviteCount === 1 ? "invite" : "invites"} will be sent`;

  return (
    <>
      <Head>
        <title>Create a group — Madori</title>
      </Head>

      <div className="px-4 py-10 max-w-5xl mx-auto">
        <Card>
          <CardContent className="pt-6 space-y-6">
            <div className="flex items-start gap-3">
              <GroupTile color={color} size="md" />
              <div className="min-w-0 flex-1">
                <h1 className="text-lg font-semibold leading-tight">
                  Create a group
                </h1>
                <p className="text-sm text-muted-foreground">
                  {activeSpace
                    ? `A named set of people in “${activeSpace.name}”. Everyone in it gets access to the space.`
                    : "A named set of people in this space. Everyone in it gets access to the space."}
                </p>
              </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
              <div className="space-y-1.5">
                <Label htmlFor="group-title">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="group-title"
                  type="text"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  required
                  maxLength={255}
                  placeholder="Creative team"
                  autoFocus
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="group-description">
                  Description{" "}
                  <span className="text-muted-foreground font-normal">
                    Optional
                  </span>
                </Label>
                <MarkdownEditor
                  id="group-description"
                  ariaLabel="Description"
                  value={description}
                  onChange={setDescription}
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="group-invites">
                  Invite members{" "}
                  <span className="text-muted-foreground font-normal">
                    Press ↵ or comma to add
                  </span>
                </Label>
                <EmailChipInput
                  inputId="group-invites"
                  value={invites}
                  onChange={setInvites}
                  maxItems={MAX_INVITES}
                />
                <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                  <span>{inviteSummary}</span>
                  {inviteCount >= MAX_INVITES && (
                    <span className="text-destructive">
                      Limit of {MAX_INVITES} reached
                    </span>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  You&apos;re added to the group automatically. Existing users
                  join immediately; others get an email invite to sign up.
                </p>
              </div>

              <div className="space-y-1.5">
                <Label>Color</Label>
                <ColorSwatchPicker
                  value={color}
                  onChange={setColor}
                  ariaLabel="Group color"
                />
              </div>

              {error && (
                <Alert variant="destructive">
                  <Info className="h-4 w-4" aria-hidden />
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="flex items-center justify-end gap-2 pt-2 border-t">
                <Button
                  type="button"
                  variant="outline"
                  onClick={goBack}
                  disabled={isSubmitting}
                >
                  Cancel
                </Button>
                <Button
                  type="submit"
                  disabled={isSubmitting || !title.trim() || !spaceIri}
                >
                  {isSubmitting ? "Creating…" : "Create group"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default NewGroupPage;
