import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useRef, useState } from "react";
import { Download, Lock, LockOpen, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import SpaceTile from "@/components/spaces/SpaceTile";
import SpaceBillingCard from "@/components/spaces/SpaceBillingCard";
import SpaceConnectCard from "@/components/spaces/SpaceConnectCard";
import InvoiceBrandingCard from "@/components/spaces/InvoiceBrandingCard";
import ExpenseCategoriesCard from "@/components/spaces/ExpenseCategoriesCard";
import DeleteSpaceDialog from "@/components/spaces/DeleteSpaceDialog";
import ChangeVisibilityDialog from "@/components/spaces/ChangeVisibilityDialog";

// Billing (Stripe) is off until a real payment system is wired up. Flip
// NEXT_PUBLIC_BILLING_ENABLED=true (and configure the Stripe env) to surface
// the plan/upgrade card again. Off = the card isn't mounted or fetched.
const BILLING_ENABLED = process.env.NEXT_PUBLIC_BILLING_ENABLED === "true";

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  role: string;
  createdAt: string;
  expiresAt: string;
}

/**
 * Admin-only space settings (`/spaces/{id}/settings`): the space's details and
 * appearance, plus data export and the danger zone. Member / invite / group
 * management lives on the Users page (`/spaces/{id}/users`).
 */
const SpaceSettings = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  // Pending-invite count powers the "make private" warning dialog only.
  const [invites, setInvites] = useState<PendingInvite[]>([]);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Dirty-tracked metadata form.
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [color, setColor] = useState<string | null>(null);
  const [initial, setInitial] = useState({
    name: "",
    description: "",
    color: null as string | null,
  });
  const formInitialized = useRef(false);
  const [isSaving, setIsSaving] = useState(false);

  const [deleteOpen, setDeleteOpen] = useState(false);
  const [visibilityOpen, setVisibilityOpen] = useState(false);

  // Space data export.
  const [isExporting, setIsExporting] = useState(false);
  const [exportMessage, setExportMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  const load = useCallback(async () => {
    if (!spaceId) return;
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load space.");
      const data: Space = await res.json();
      setSpace(data);

      // Initialise the metadata form once so a background reload doesn't
      // clobber in-progress edits.
      if (!formInitialized.current) {
        const snap = {
          name: data.name,
          description: data.description ?? "",
          color: data.color,
        };
        setName(snap.name);
        setDescription(snap.description);
        setColor(snap.color);
        setInitial(snap);
        formInitialized.current = true;
      }

      const isMembershipAdmin = data.userMemberships.some(
        (m) => user && m.user.id === user.id && m.role === "admin",
      );
      if (isMembershipAdmin) {
        const inviteRes = await fetch(
          `${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}/invites`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (inviteRes.ok) {
          const inviteData: { invites: PendingInvite[] } = await inviteRes.json();
          setInvites(inviteData.invites);
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load space.");
    }
  }, [spaceId, user]);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Load once per auth/space change — NOT on `load` identity, so an unstable
  // callback ref can't re-fetch (and blank the page) on every render.
  useEffect(() => {
    if (isAuthenticated && spaceId) {
      void load();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, spaceId]);

  const isAdmin =
    !!space &&
    !!user &&
    space.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    );

  useEffect(() => {
    if (space && user && !isAdmin && spaceId) {
      router.replace(`/spaces/${spaceId}`);
    }
  }, [space, user, isAdmin, spaceId, router]);

  const isCreator = !!space && !!user && space.createdBy?.id === user.id;

  const isDirty =
    name !== initial.name ||
    description !== initial.description ||
    color !== initial.color;

  const handleSave = async () => {
    if (!space || !name.trim()) return;
    setIsSaving(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim() || null,
          color,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail || data["hydra:description"] || "Failed to save changes.",
        );
      }
      setInitial({ name: name.trim(), description, color });
      setName(name.trim());
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save changes.");
    } finally {
      setIsSaving(false);
    }
  };

  const handleExport = async () => {
    if (!space) return;
    setIsExporting(true);
    setExportMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/export`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}),
        },
      );
      if (res.status === 409) {
        setExportMessage({
          text: "An export of this space is already being prepared — you'll get an email when it's ready.",
          kind: "success",
        });
        return;
      }
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to request the export.");
      }
      setExportMessage({
        text: "Export started — we'll email you a download link when it's ready. Links expire after 7 days.",
        kind: "success",
      });
    } catch (err) {
      setExportMessage({
        text:
          err instanceof Error ? err.message : "Failed to request the export.",
        kind: "error",
      });
    } finally {
      setIsExporting(false);
    }
  };

  const handleSpaceDeleted = async () => {
    setDeleteOpen(false);
    await refresh();
    void router.push("/spaces");
  };

  const handleVisibilityChanged = async () => {
    setVisibilityOpen(false);
    await load();
    await refresh();
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-md text-center">
          <h1 className="text-xl font-semibold mb-2">Space not found</h1>
          <p className="text-muted-foreground mb-4">
            It may have been deleted, or you no longer have access.
          </p>
          <Button asChild variant="outline">
            <Link href="/spaces">Back to spaces</Link>
          </Button>
        </div>
      </div>
    );
  }

  if (!space) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAdmin) return null;

  return (
    <>
      <Head>
        <title>Space settings · {space.name}</title>
      </Head>
      <div className="mx-auto max-w-5xl px-4 py-8 pb-24">
        <div className="mb-6">
          <h1 className="text-2xl font-bold">Space settings</h1>
          <p className="text-sm text-muted-foreground">
            Manage the details and appearance of this space. Members, invites,
            and groups live on the{" "}
            <Link
              href={`/spaces/${space.id}/users`}
              className="text-primary hover:underline"
            >
              Users page
            </Link>
            .
          </p>
        </div>

        {error && (
          <Alert variant="destructive" className="mb-4">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {/* Details & appearance */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-5">
            <h2 className="font-semibold">Details</h2>

            <div className="flex items-start gap-4">
              <div className="flex shrink-0 flex-col items-center gap-1">
                <SpaceTile
                  name={name || space.name}
                  color={color ?? resolveSpaceColor(space)}
                  isPersonal={space.isPersonal}
                  size="lg"
                />
                <span className="text-xs text-muted-foreground">Preview</span>
              </div>
              <div className="flex-1 space-y-1.5">
                <Label htmlFor="space-name">
                  Name <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="space-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  maxLength={120}
                />
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="space-description">
                Description{" "}
                <span className="text-muted-foreground font-normal">
                  Markdown supported
                </span>
              </Label>
              <MarkdownEditor
                id="space-description"
                ariaLabel="Description"
                value={description}
                onChange={setDescription}
              />
            </div>

            <div className="space-y-2">
              <Label>
                Color{" "}
                <span className="text-muted-foreground font-normal">
                  Used for the tile and accents
                </span>
              </Label>
              <ColorSwatchPicker
                value={color ?? resolveSpaceColor(space)}
                onChange={(c) => setColor(c)}
                ariaLabel="Space color"
                disabled={isSaving}
              />
            </div>
          </CardContent>
        </Card>

        {/* Plan & billing — personal spaces are never billable, and the whole
            surface is gated off until Stripe is wired up (BILLING_ENABLED). */}
        {BILLING_ENABLED && !space.isPersonal && (
          <SpaceBillingCard spaceId={space.id} />
        )}

        {/* Payments — collect client invoice payments via Stripe Connect
            (#connect). Personal spaces don't invoice clients. */}
        {!space.isPersonal && <SpaceConnectCard spaceId={space.id} />}

        {/* Invoice branding (#669): logo, terms, numbering. */}
        <InvoiceBrandingCard space={space} onSaved={load} />

        {/* Managed expense categories (#671). */}
        <ExpenseCategoriesCard spaceIri={space["@id"]} />

        {/* Export space data */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-3">
            <h2 className="font-semibold">Export space data</h2>
            <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
              <div className="min-w-0">
                <p className="font-medium">Download everything in this space</p>
                <p className="text-sm text-muted-foreground">
                  Boards, tasks, pages, comments, and
                  attachments, bundled as a zip. We&apos;ll email you a
                  download link when it&apos;s ready — links expire after 7
                  days.
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5 shrink-0"
                onClick={() => void handleExport()}
                disabled={isExporting}
              >
                <Download className="h-4 w-4" aria-hidden />
                {isExporting ? "Requesting…" : "Request export"}
              </Button>
            </div>
            {exportMessage && (
              <Alert
                variant={
                  exportMessage.kind === "error" ? "destructive" : "default"
                }
              >
                <AlertDescription>{exportMessage.text}</AlertDescription>
              </Alert>
            )}
          </CardContent>
        </Card>

        {/* Danger zone */}
        <Card className="mb-6 border-destructive/40">
          <CardContent className="pt-6 space-y-3">
            <h2 className="font-semibold text-destructive">Danger zone</h2>
            {space.isPersonal ? (
              <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
                <div className="min-w-0">
                  <p className="font-medium flex items-center gap-1.5">
                    <Lock className="h-4 w-4" aria-hidden />
                    Personal spaces can&apos;t be deleted
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Your Private space is always available and stays with your
                    account.
                  </p>
                </div>
                <Button variant="outline" size="sm" disabled className="gap-1.5">
                  <Lock className="h-4 w-4" aria-hidden />
                  Locked
                </Button>
              </div>
            ) : (
              <>
                {isCreator && (
                  <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
                    <div className="min-w-0">
                      <p className="font-medium flex items-center gap-1.5">
                        {space.visibility === "shared" ? (
                          <Lock className="h-4 w-4" aria-hidden />
                        ) : (
                          <LockOpen className="h-4 w-4" aria-hidden />
                        )}
                        {space.visibility === "shared"
                          ? "Make this space private"
                          : "Make this space shared"}
                      </p>
                      <p className="text-sm text-muted-foreground">
                        {space.visibility === "shared"
                          ? "Removes every member and pending invite — only you keep access. Content stays, but becomes visible to you alone."
                          : "Re-opens this space so you can invite members again."}
                      </p>
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      className="gap-1.5"
                      onClick={() => setVisibilityOpen(true)}
                    >
                      {space.visibility === "shared" ? (
                        <>
                          <Lock className="h-4 w-4" aria-hidden />
                          Make private
                        </>
                      ) : (
                        <>
                          <LockOpen className="h-4 w-4" aria-hidden />
                          Make shared
                        </>
                      )}
                    </Button>
                  </div>
                )}
                <div className="flex items-center justify-between gap-3 rounded-md border border-destructive/40 px-4 py-3">
                  <div className="min-w-0">
                    <p className="font-medium text-destructive">
                      Delete this space
                    </p>
                    <p className="text-sm text-muted-foreground">
                      Permanently removes {space.name} and all{" "}
                      {space.boardsCount} board
                      {space.boardsCount === 1 ? "" : "s"} and{" "}
                      {space.pagesCount} page
                      {space.pagesCount === 1 ? "" : "s"}. This can&apos;t be
                      undone.
                    </p>
                  </div>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="gap-1.5"
                    onClick={() => setDeleteOpen(true)}
                  >
                    <Trash2 className="h-4 w-4" aria-hidden />
                    Delete space
                  </Button>
                </div>
              </>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Sticky save bar for the metadata form. */}
      <div className="sticky bottom-0 border-t bg-background/95 backdrop-blur">
        <div className="mx-auto max-w-5xl px-4 py-3 flex items-center justify-between gap-3">
          <span className="text-sm text-muted-foreground">
            {isDirty ? "Unsaved changes" : "All changes saved"}
          </span>
          <div className="flex items-center gap-2">
            <Button asChild variant="outline" size="sm" disabled={isSaving}>
              <Link href={`/spaces/${space.id}`}>Cancel</Link>
            </Button>
            <Button
              size="sm"
              className="gap-1.5"
              disabled={!isDirty || !name.trim() || isSaving}
              onClick={handleSave}
            >
              {isSaving ? "Saving…" : "Save changes"}
            </Button>
          </div>
        </div>
      </div>

      {!space.isPersonal && (
        <DeleteSpaceDialog
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
          space={space}
          twoFactorEnabled={user.twoFactor?.enabled ?? false}
          onDeleted={handleSpaceDeleted}
        />
      )}

      {!space.isPersonal && isCreator && (
        <ChangeVisibilityDialog
          open={visibilityOpen}
          onOpenChange={setVisibilityOpen}
          space={space}
          target={space.visibility === "shared" ? "private" : "shared"}
          pendingInviteCount={invites.length}
          onChanged={handleVisibilityChanged}
        />
      )}
    </>
  );
};

export default SpaceSettings;
