import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Eye, FolderKanban, Info } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import EmailChipInput from "@/components/common/EmailChipInput";

const MAX_INVITES = 50;

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
const NewSpacePage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [invites, setInvites] = useState<string[]>([]);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
          invites,
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
        <title>Create a space — Aura</title>
      </Head>

      <main className="px-4 py-10 max-w-2xl mx-auto">
        <Card>
          <CardContent className="pt-6 space-y-6">
            <div className="flex items-start gap-3">
              <span
                aria-hidden
                className="inline-flex items-center justify-center h-10 w-10 rounded-md bg-emerald-600 text-white shrink-0"
              >
                <FolderKanban className="h-5 w-5" />
              </span>
              <div className="min-w-0 flex-1">
                <h1 className="text-lg font-semibold leading-tight">
                  Create a space
                </h1>
                <p className="text-sm text-muted-foreground">
                  Spaces have their own members, projects, and pages.
                </p>
              </div>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label="Cancel"
                onClick={goBack}
              >
                ×
              </Button>
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
                    Optional · 1–2 lines
                  </span>
                </Label>
                <Textarea
                  id="space-description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  maxLength={500}
                  rows={2}
                  placeholder="Cross-functional rollout for the Spring 2026 product line."
                />
              </div>

              <div className="space-y-1.5">
                <div className="flex items-baseline justify-between">
                  <Label htmlFor="space-invites">
                    Invite initial members{" "}
                    <span className="text-muted-foreground font-normal">
                      Press ↵ or comma to add
                    </span>
                  </Label>
                </div>
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

              <Alert>
                <Eye className="h-4 w-4" aria-hidden />
                <AlertDescription>
                  You&apos;ll be the first admin. Only invited members will see
                  this space.
                </AlertDescription>
              </Alert>

              {error && (
                <Alert variant="destructive">
                  <Info className="h-4 w-4" aria-hidden />
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="flex items-center justify-between gap-2 pt-2 border-t">
                <span className="text-xs text-muted-foreground">
                  <kbd className="rounded border bg-muted px-1.5 py-0.5 text-[10px] font-mono">
                    esc
                  </kbd>{" "}
                  to cancel
                </span>
                <div className="flex items-center gap-2">
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
                    className="bg-emerald-600 hover:bg-emerald-500 text-white"
                  >
                    {isSubmitting ? "Creating…" : "Create space"}
                  </Button>
                </div>
              </div>
            </form>
          </CardContent>
        </Card>
      </main>
    </>
  );
};

export default NewSpacePage;
