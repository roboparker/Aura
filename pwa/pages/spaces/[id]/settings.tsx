import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ChevronDown,
  Download,
  Lock,
  LockOpen,
  Mail,
  Trash2,
  UserPlus,
  X,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import {
  useActiveSpace,
  type Space,
  type SpaceMember,
} from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { formatRelative } from "@/lib/relativeTime";
import { displayName } from "@/lib/userDisplay";
import { cn } from "@/lib/utils";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import SpaceTile from "@/components/spaces/SpaceTile";
import SpaceBillingCard from "@/components/spaces/SpaceBillingCard";
import DeleteSpaceDialog from "@/components/spaces/DeleteSpaceDialog";
import ChangeVisibilityDialog from "@/components/spaces/ChangeVisibilityDialog";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";

// Billing (Stripe) is off until a real payment system is wired up. Flip
// NEXT_PUBLIC_BILLING_ENABLED=true (and configure the Stripe env) to surface
// the plan/upgrade card again. Off = the card isn't mounted or fetched.
const BILLING_ENABLED = process.env.NEXT_PUBLIC_BILLING_ENABLED === "true";

type Role = "admin" | "member";

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  role: string;
  createdAt: string;
  expiresAt: string;
}

const toAvatarUser = (m: SpaceMember): AvatarUser => ({
  ...m,
  personalizedColor: m.personalizedColor ?? "#64748b",
});

/** Compact role picker (Admin / Member) — used per member row and on invite. */
const RoleSelect = ({
  value,
  onChange,
  disabled,
}: {
  value: Role;
  onChange: (role: Role) => void;
  disabled?: boolean;
}) => (
  <DropdownMenu>
    <DropdownMenuTrigger asChild>
      <button
        type="button"
        disabled={disabled}
        className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium capitalize hover:bg-accent disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-ring"
      >
        <span
          className={cn(
            "h-1.5 w-1.5 rounded-full",
            value === "admin" ? "bg-violet-500" : "bg-emerald-500",
          )}
        />
        {value}
        <ChevronDown className="h-3 w-3 text-muted-foreground" aria-hidden />
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" className="min-w-[120px]">
      <DropdownMenuItem onSelect={() => onChange("admin")}>Admin</DropdownMenuItem>
      <DropdownMenuItem onSelect={() => onChange("member")}>Member</DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
);

/**
 * Admin-only space settings (`/spaces/{id}/settings`). A single inline
 * form for the space's details, appearance, and members, with a sticky
 * save bar for the metadata fields. Member role changes, removals, and
 * invites apply immediately (they're their own API calls); only name /
 * description / visibility / color are batched behind "Save changes".
 */
const SpaceSettings = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
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

  // Invite-by-email.
  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState<Role>("member");
  const [isInviting, setIsInviting] = useState(false);
  const [inviteMessage, setInviteMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

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

      // Initialise the metadata form once so a background reload (after
      // a member change) doesn't clobber in-progress edits.
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

  const handleChangeRole = async (membership: { id: string }, role: Role) => {
    if (!space) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ role }),
      },
    );
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to change role.");
      return;
    }
    await load();
    await refresh();
  };

  const handleRemoveMember = async (membership: { id: string }, label: string) => {
    if (!space) return;
    if (!window.confirm(`Remove ${label} from this space?`)) return;
    setError(null);
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members/${encodeURIComponent(membership.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok && res.status !== 204) {
      const data = await res.json().catch(() => ({}));
      setError(data.error || "Failed to remove member.");
      return;
    }
    await load();
    await refresh();
  };

  const handleInvite = async () => {
    if (!space || !inviteEmail.trim()) return;
    setIsInviting(true);
    setInviteMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: inviteEmail.trim(), role: inviteRole }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error(data.error || "Failed to invite.");
      setInviteMessage({
        text:
          data.status === "added"
            ? `${data.email} is now a member.`
            : `Invitation sent to ${data.email}.`,
        kind: "success",
      });
      setInviteEmail("");
      await load();
      await refresh();
    } catch (err) {
      setInviteMessage({
        text: err instanceof Error ? err.message : "Failed to invite.",
        kind: "error",
      });
    } finally {
      setIsInviting(false);
    }
  };

  const handleRevokeInvite = async (invite: PendingInvite) => {
    if (!space) return;
    if (!window.confirm(`Revoke the invitation for ${invite.email}?`)) return;
    const res = await fetch(
      `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/invites/${encodeURIComponent(invite.id)}`,
      { method: "DELETE", credentials: "include" },
    );
    if (!res.ok) {
      setError("Failed to revoke invitation.");
      return;
    }
    setInvites((prev) => prev.filter((i) => i.id !== invite.id));
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
      <main className="min-h-screen bg-muted px-4 py-12">
        <div className="mx-auto max-w-md text-center">
          <h1 className="text-xl font-semibold mb-2">Space not found</h1>
          <p className="text-muted-foreground mb-4">
            It may have been deleted, or you no longer have access.
          </p>
          <Button asChild variant="outline">
            <Link href="/spaces">Back to spaces</Link>
          </Button>
        </div>
      </main>
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
      <main className="mx-auto max-w-3xl px-4 py-8 pb-24">
        <div className="mb-6">
          <h1 className="text-2xl font-bold">Space settings</h1>
          <p className="text-sm text-muted-foreground">
            Manage the details, appearance, and members of this space.
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

        {/* Member management only applies to shared spaces. */}
        {space.visibility === "shared" && (
          <>
        {/* Members */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-4">
            <h2 className="font-semibold">
              Members{" "}
              <span className="text-muted-foreground font-normal">
                {space.userMemberships.length}
              </span>
            </h2>

            <form
              className="flex flex-wrap items-center gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                void handleInvite();
              }}
            >
              <div className="relative flex-1 min-w-[180px]">
                <Mail
                  className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
                  aria-hidden
                />
                <Input
                  type="email"
                  value={inviteEmail}
                  onChange={(e) => setInviteEmail(e.target.value)}
                  placeholder="Invite by email…"
                  aria-label="Invite by email"
                  className="pl-8"
                />
              </div>
              <RoleSelect value={inviteRole} onChange={setInviteRole} />
              <Button
                type="submit"
                size="sm"
                className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
                disabled={isInviting || !inviteEmail.trim()}
              >
                <UserPlus className="h-4 w-4" aria-hidden />
                {isInviting ? "Adding…" : "Add"}
              </Button>
            </form>
            {inviteMessage && (
              <p
                role="alert"
                className={cn(
                  "text-sm",
                  inviteMessage.kind === "success"
                    ? "text-muted-foreground"
                    : "text-destructive",
                )}
              >
                {inviteMessage.text}
              </p>
            )}

            <ul className="divide-y divide-border rounded-md border">
              {space.userMemberships.map((m) => {
                const isSelf = m.user.id === user.id;
                const label = displayName(m.user);
                return (
                  <li
                    key={m["@id"]}
                    className="flex items-center gap-3 px-3 py-2.5"
                  >
                    <UserAvatar user={toAvatarUser(m.user)} size="sm" />
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-1.5">
                        <span className="font-medium truncate">{label}</span>
                        {isSelf && (
                          <span className="text-xs text-muted-foreground">you</span>
                        )}
                      </div>
                      <p className="text-xs text-muted-foreground truncate">
                        {m.user.email}
                      </p>
                    </div>
                    <RoleSelect
                      value={m.role}
                      onChange={(role) => void handleChangeRole(m, role)}
                    />
                    <button
                      type="button"
                      onClick={() => void handleRemoveMember(m, label)}
                      aria-label={`Remove ${label}`}
                      className="text-muted-foreground hover:text-destructive p-1"
                    >
                      <X className="h-4 w-4" aria-hidden />
                    </button>
                  </li>
                );
              })}
            </ul>
          </CardContent>
        </Card>

        {/* Pending invites */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-3">
            <h2 className="font-semibold">
              Pending invites{" "}
              <span className="text-muted-foreground font-normal">
                {invites.length}
              </span>
            </h2>
            {invites.length === 0 ? (
              <div className="rounded-md border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                No pending invitations — invited teammates will appear here until
                they accept.
              </div>
            ) : (
              <ul className="divide-y divide-border rounded-md border">
                {invites.map((invite) => (
                  <li
                    key={invite.id}
                    className="flex items-center gap-3 px-3 py-2.5"
                  >
                    <Mail
                      className="h-4 w-4 text-muted-foreground shrink-0"
                      aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                      <p className="font-medium truncate">{invite.email}</p>
                      <p className="text-xs text-muted-foreground truncate">
                        invited by {invite.invitedBy} · expires{" "}
                        {formatRelative(invite.expiresAt)}
                      </p>
                    </div>
                    <Badge
                      variant={invite.role === "admin" ? "secondary" : "outline"}
                      className="shrink-0 capitalize"
                    >
                      {invite.role}
                    </Badge>
                    <Badge variant="outline" className="shrink-0">
                      Sent
                    </Badge>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="text-destructive hover:text-destructive"
                      onClick={() => void handleRevokeInvite(invite)}
                    >
                      Revoke
                    </Button>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
          </>
        )}

        {/* Plan & billing — personal spaces are never billable, and the whole
            surface is gated off until Stripe is wired up (BILLING_ENABLED). */}
        {BILLING_ENABLED && !space.isPersonal && (
          <SpaceBillingCard spaceId={space.id} />
        )}

        {/* Export space data */}
        <Card className="mb-6">
          <CardContent className="pt-6 space-y-3">
            <h2 className="font-semibold">Export space data</h2>
            <div className="flex items-center justify-between gap-3 rounded-md border px-4 py-3">
              <div className="min-w-0">
                <p className="font-medium">Download everything in this space</p>
                <p className="text-sm text-muted-foreground">
                  Projects, tasks, pages, discussions, comments, and
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
                      {space.projectsCount} project
                      {space.projectsCount === 1 ? "" : "s"} and{" "}
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
      </main>

      {/* Sticky save bar for the metadata form. */}
      <div className="sticky bottom-0 border-t bg-background/95 backdrop-blur">
        <div className="mx-auto max-w-3xl px-4 py-3 flex items-center justify-between gap-3">
          <span className="text-sm text-muted-foreground">
            {isDirty ? "Unsaved changes" : "All changes saved"}
          </span>
          <div className="flex items-center gap-2">
            <Button
              asChild
              variant="outline"
              size="sm"
              disabled={isSaving}
            >
              <Link href={`/spaces/${space.id}`}>Cancel</Link>
            </Button>
            <Button
              size="sm"
              className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
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
