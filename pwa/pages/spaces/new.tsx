import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Info, Lock, LockOpen } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { trackEvent } from "@/lib/analytics";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import EmailChipInput from "@/components/common/EmailChipInput";
import SpaceTile from "@/components/spaces/SpaceTile";
import { pageTitle } from "@/lib/pageTitle";

const MAX_INVITES = 50;

const VISIBILITY_OPTIONS: {
  key: "private" | "shared";
  label: string;
  description: string;
  icon: typeof Lock;
}[] = [
  {
    key: "private",
    label: "Private",
    description: "Only you can see this space.",
    icon: Lock,
  },
  {
    key: "shared",
    label: "Shared",
    description: "Invite teammates to collaborate.",
    icon: LockOpen,
  },
];

interface CreatedSpace {
  "@id": string;
  id: string;
}

/**
 * Full-page Create Space form (replaces the old modal flow). POSTs a
 * single `{name, description, invites}` payload — the backend
 * `SpaceCreateProcessor` does the atomic split between direct
 * memberships (known emails) and pending invites (unknown emails),
 * so the page hands off and navigates to the new space's detail on
 * success.
 */
type Visibility = "private" | "shared";

const NewSpacePage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [visibility, setVisibility] = useState<Visibility>("private");
  // Default the swatch to the user's own color so the new space's
  // tile matches their avatar out of the box; they can pick a
  // different palette entry below.
  const [color, setColor] = useState<string>(
    () => user?.personalizedColor ?? AVATAR_PALETTE[0],
  );
  const [invites, setInvites] = useState<string[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // If user data lands after first render (auth still loading), sync
  // the default swatch once it does.
  useEffect(() => {
    if (user?.personalizedColor) setColor(user.personalizedColor);
  }, [user?.personalizedColor]);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const goBack = useCallback(() => {
    // Prefer the actual previous page when it's an in-app entry; fall
    // back to the spaces list so Escape from a deep-link never strands
    // the user on /spaces/new.
    if (window.history.length > 1) {
      router.back();
    } else {
      router.push("/spaces");
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
          visibility,
          color,
          // Invites only make sense when the space is shared — drop
          // them on the wire when the user picked private so the
          // backend can't accidentally seed invites that won't ever
          // be reachable.
          invites: visibility === "shared" ? invites : [],
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
      const space: CreatedSpace = await res.json();
      trackEvent("space-create", { visibility });
      // Refresh the sidebar / context list before navigating so the new
      // space is already in the list when the detail page mounts.
      await refresh();
      router.push(`/spaces/${space.id}`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create space.");
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
      ? "Invite teammates to join (optional)"
      : `${inviteCount} ${inviteCount === 1 ? "invite" : "invites"} will be sent`;

  return (
    <>
      <Head>
        <title>{pageTitle("Create a space")}</title>
      </Head>

      <div className="px-4 py-10 max-w-5xl mx-auto">
        <Card>
          <CardContent className="pt-6 space-y-6">
            <div className="flex items-start gap-3">
              <SpaceTile
                name={name || "?"}
                color={color}
                isPersonal={false}
                size="md"
              />
              <div className="min-w-0 flex-1">
                <h1 className="text-lg font-semibold leading-tight">
                  Create a space
                </h1>
                <p className="text-sm text-muted-foreground">
                  Spaces have their own members, boards, and pages.
                </p>
              </div>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5">
              <div className="space-y-1.5">
                <Label htmlFor="space-name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="space-name"
                  type="text"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  required
                  maxLength={255}
                  placeholder="Spring 2026 launch"
                  autoFocus
                />
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="space-description">
                  Description{" "}
                  <span className="text-muted-foreground font-normal">
                    Optional
                  </span>
                </Label>
                <MarkdownEditor
                  id="space-description"
                  ariaLabel="Description"
                  value={description}
                  onChange={setDescription}
                />
              </div>

              <div className="space-y-1.5">
                <Label>Visibility</Label>
                <div
                  role="radiogroup"
                  aria-label="Visibility"
                  className="grid grid-cols-2 gap-2"
                >
                  {VISIBILITY_OPTIONS.map(({ key, label, description, icon: Icon }) => {
                    const selected = visibility === key;
                    return (
                      <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => setVisibility(key)}
                        className={cn(
                          "flex items-start gap-2 rounded-md border p-3 text-left transition-colors",
                          selected
                            ? "border-emerald-500/60 bg-emerald-500/5"
                            : "border-input hover:bg-muted/50",
                        )}
                      >
                        <Icon
                          className={cn(
                            "h-4 w-4 mt-0.5 shrink-0",
                            selected
                              ? "text-emerald-600 dark:text-emerald-400"
                              : "text-muted-foreground",
                          )}
                          aria-hidden
                        />
                        <span className="min-w-0">
                          <span className="block text-sm font-medium">
                            {label}
                          </span>
                          <span className="block text-xs text-muted-foreground">
                            {description}
                          </span>
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>

              {visibility === "shared" && (
                <div className="space-y-1.5">
                  <Label htmlFor="space-invites">
                    Invite initial members{" "}
                    <span className="text-muted-foreground font-normal">
                      Press ↵ or comma to add
                    </span>
                  </Label>
                  <EmailChipInput
                    inputId="space-invites"
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
                </div>
              )}

              <div className="space-y-1.5">
                <Label>Color</Label>
                <ColorSwatchPicker
                  value={color}
                  onChange={setColor}
                  ariaLabel="Space color"
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
                  disabled={isSubmitting || !name.trim()}
                >
                  {isSubmitting ? "Creating…" : "Create space"}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </>
  );
};

export default NewSpacePage;
