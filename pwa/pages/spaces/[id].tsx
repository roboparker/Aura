import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import AttachmentsPanel, {
  type Attachment,
} from "@/components/tasks/AttachmentsPanel";
import {
  SpaceDiscussionsList,
  SpacePagesList,
  SpaceProjectsList,
  SpaceTasksList,
} from "@/components/spaces/SpaceContentTabs";
import SpaceColorPicker from "@/components/spaces/SpaceColorPicker";

// Tab keys live in the URL (`?tab=...`) so deep links and the
// browser back-button work naturally. Unknown values fall back to
// the Overview tab.
const TABS = ["overview", "projects", "discussions", "pages", "tasks", "files"] as const;
type TabKey = (typeof TABS)[number];
const isTabKey = (v: unknown): v is TabKey =>
  typeof v === "string" && (TABS as readonly string[]).includes(v);

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  role: string;
  createdAt: string;
  expiresAt: string;
}

const SpaceDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { refresh } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const spaceId = typeof id === "string" ? id : null;

  const [space, setSpace] = useState<Space | null>(null);
  const [invites, setInvites] = useState<PendingInvite[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Edit metadata (admin-only)
  const [editName, setEditName] = useState("");
  const [editDescription, setEditDescription] = useState("");
  const [isSavingMeta, setIsSavingMeta] = useState(false);

  // Member add (admin-only)
  const [newMemberEmail, setNewMemberEmail] = useState("");
  const [isAddingMember, setIsAddingMember] = useState(false);
  const [memberMessage, setMemberMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  const handleAttach = async (mediaObjectIri: string) => {
    if (!space) return;
    const current = (space.attachments ?? []).map((a) => a["@id"]);
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ attachments: [...current, mediaObjectIri] }),
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      throw new Error(
        data.detail || data["hydra:description"] || "Failed to attach file.",
      );
    }
    const updated: Space = await res.json();
    setSpace(updated);
  };

  const handleDetach = async (attachment: Attachment) => {
    if (!space) return;
    const nextIris = (space.attachments ?? [])
      .filter((a) => a["@id"] !== attachment["@id"])
      .map((a) => a["@id"]);
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ attachments: nextIris }),
    });
    if (!res.ok) {
      throw new Error("Failed to remove attachment.");
    }
    const updated: Space = await res.json();
    setSpace(updated);
  };

  // Tab state — driven by `?tab=` so the back button works and deep
  // links land on the chosen tab. Falls back to overview for missing
  // or unknown values.
  const queryTab = router.query.tab;
  const activeTab: TabKey = isTabKey(queryTab) ? queryTab : "overview";
  const handleTabChange = (value: string) => {
    if (!isTabKey(value)) return;
    const nextTab: TabKey = value;
    // Carry forward everything except the old `tab` value so a
    // deep-link with extra search params (e.g. a future ?invite=…)
    // survives the switch.
    const nextQuery: Record<string, string | string[]> = {};
    for (const [k, v] of Object.entries(router.query)) {
      if (k === "tab" || v === undefined) continue;
      nextQuery[k] = v as string | string[];
    }
    if (nextTab !== "overview") nextQuery.tab = nextTab;
    void router.replace(
      { pathname: router.pathname, query: nextQuery },
      undefined,
      { shallow: true },
    );
  };

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const isAdmin =
    !!space &&
    !!user &&
    space.userMemberships.some(
      (m) => m.user.id === user.id && m.role === "admin",
    );

  const load = useCallback(async () => {
    if (!spaceId) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(`${ENTRYPOINT}/spaces/${encodeURIComponent(spaceId)}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (res.status === 404) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load space.");
      const data: Space = await res.json();
      setSpace(data);
      setEditName(data.name);
      setEditDescription(data.description ?? "");

      // Pending invites are admin-only on the API; non-admins still
      // hit the endpoint cleanly (it returns 403/404), so guard here
      // to avoid spurious errors in the console.
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
      } else {
        setInvites([]);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load space.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceId, user]);

  useEffect(() => {
    if (isAuthenticated && spaceId) {
      void load();
    }
  }, [isAuthenticated, spaceId, load]);

  const handleSaveMeta = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!space || !editName.trim()) return;
    setIsSavingMeta(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          name: editName.trim(),
          description: editDescription.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail || data["hydra:description"] || "Failed to save changes.",
        );
      }
      await load();
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save changes.");
    } finally {
      setIsSavingMeta(false);
    }
  };

  const handleAddMember = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!space || !newMemberEmail.trim()) return;
    setIsAddingMember(true);
    setMemberMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/spaces/${encodeURIComponent(space.id)}/members`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email: newMemberEmail.trim() }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(data.error || "Failed to add member.");
      }
      // The endpoint returns either status=added or status=invited;
      // surface the right confirmation either way so the admin
      // doesn't have to guess whether an email went out.
      const confirmation =
        data.status === "added"
          ? `${data.email} is now a member of this space.`
          : `Sent a sign-up invitation to ${data.email}.`;
      setMemberMessage({ text: confirmation, kind: "success" });
      setNewMemberEmail("");
      await load();
    } catch (err) {
      setMemberMessage({
        text: err instanceof Error ? err.message : "Failed to add member.",
        kind: "error",
      });
    } finally {
      setIsAddingMember(false);
    }
  };

  const handleRevokeInvite = async (invite: PendingInvite) => {
    if (!space) return;
    if (
      !window.confirm(
        `Revoke the pending invitation for ${invite.email}? They won't be able to use the existing sign-up link.`,
      )
    ) {
      return;
    }
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

  const handleDeleteSpace = async () => {
    if (!space) return;
    if (
      !window.confirm(
        `Delete "${space.name}"? Every project, discussion, and custom field in this space goes with it. This can't be undone.`,
      )
    ) {
      return;
    }
    const res = await fetch(`${ENTRYPOINT}${space["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      const data = await res.json().catch(() => ({}));
      setError(data.detail || "Failed to delete space.");
      return;
    }
    await refresh();
    router.push("/spaces");
  };

  const handleRemoveMembership = async (membershipIri: string, label: string) => {
    if (!space) return;
    if (!window.confirm(`Remove ${label} from this space?`)) {
      return;
    }
    const res = await fetch(`${ENTRYPOINT}${membershipIri}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      // SpaceMembership isn't an API resource yet — the action will
      // probably 405; surface that so the user knows the UI hook is
      // ahead of the API. PR follow-up will add the endpoint.
      setError(
        "Removing members directly isn't supported yet. Until then, ask the member to leave the space themselves.",
      );
      return;
    }
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
        <Card className="max-w-2xl mx-auto">
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Space not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not be a member.
            </p>
            <Link href="/spaces" className="text-primary font-medium">
              Back to spaces
            </Link>
          </CardContent>
        </Card>
      </main>
    );
  }

  return (
    <>
      <Head>
        <title>{space ? `${space.name} - Aura` : "Space - Aura"}</title>
      </Head>
      <main className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <Link
            href="/spaces"
            className="inline-block text-sm text-primary hover:underline mb-3 no-underline"
          >
            ← All spaces
          </Link>

          {isLoading || !space ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <>
              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              {/* Space header sits OUTSIDE the tab strip — name + role
                  badges are global context. Edit-metadata moves into
                  the Overview tab below. */}
              <div className="flex items-start justify-between gap-2 mb-4">
                <h1 className="text-2xl font-bold">{space.name}</h1>
                <div className="flex gap-1">
                  {space.isPersonal && <Badge variant="secondary">Private</Badge>}
                  {isAdmin && !space.isPersonal && (
                    <Badge variant="secondary">Admin</Badge>
                  )}
                </div>
              </div>

              <Tabs value={activeTab} onValueChange={handleTabChange}>
                <TabsList className="mb-4">
                  <TabsTrigger value="overview">Overview</TabsTrigger>
                  <TabsTrigger value="projects">Projects</TabsTrigger>
                  <TabsTrigger value="discussions">Discussions</TabsTrigger>
                  <TabsTrigger value="pages">Pages</TabsTrigger>
                  <TabsTrigger value="tasks">Tasks</TabsTrigger>
                  <TabsTrigger value="files">Files</TabsTrigger>
                </TabsList>

                <TabsContent value="overview" className="space-y-6 mt-0">
                  {isAdmin && !space.isPersonal && (
                    <Card>
                      <CardContent className="pt-6">
                        <form onSubmit={handleSaveMeta} className="space-y-3">
                          <div className="space-y-1.5">
                            <Label htmlFor="space-name">Name</Label>
                            <Input
                              id="space-name"
                              type="text"
                              value={editName}
                              onChange={(e) => setEditName(e.target.value)}
                              maxLength={255}
                            />
                          </div>
                          <div className="space-y-1.5">
                            <Label htmlFor="space-description">Description</Label>
                            <Input
                              id="space-description"
                              type="text"
                              value={editDescription}
                              onChange={(e) => setEditDescription(e.target.value)}
                              maxLength={500}
                            />
                          </div>
                          <Button type="submit" size="sm" disabled={isSavingMeta}>
                            {isSavingMeta ? "Saving…" : "Save"}
                          </Button>
                        </form>
                      </CardContent>
                    </Card>
                  )}
                  {(!isAdmin || space.isPersonal) && space.description && (
                    <Card>
                      <CardContent className="pt-6">
                        <p className="text-sm text-muted-foreground">
                          {space.description}
                        </p>
                      </CardContent>
                    </Card>
                  )}

                  {/* Color override is admin-only but works for both
                      personal and shared spaces (personal owners are
                      admins of their own bucket). */}
                  {isAdmin && (
                    <SpaceColorPicker space={space} onSaved={async () => {
                      await load();
                      await refresh();
                    }} />
                  )}

                  <Card>
                    <CardContent className="pt-6">
                      <h2 className="text-lg font-semibold mb-3">Members</h2>
                  <ul className="flex flex-wrap items-center gap-1 mb-3" data-testid="space-member-list">
                    {space.userMemberships.map((membership) => {
                      const isSelf = membership.user.id === user.id;
                      const label = membership.user.email;
                      return (
                        <li key={membership["@id"]} data-testid="space-member">
                          <Badge variant="secondary" className="gap-1">
                            <span>
                              {label}
                              {membership.role === "admin" && (
                                <span className="ml-1 text-xs uppercase tracking-wide opacity-70">
                                  admin
                                </span>
                              )}
                              {isSelf && <span className="ml-1 text-xs opacity-70">(you)</span>}
                            </span>
                            {isAdmin && !isSelf && !space.isPersonal && (
                              <button
                                type="button"
                                onClick={() =>
                                  handleRemoveMembership(membership["@id"], label)
                                }
                                aria-label={`Remove ${label}`}
                                className="ml-0.5 text-muted-foreground hover:text-destructive bg-transparent border-0 cursor-pointer"
                              >
                                <X className="h-3 w-3" />
                              </button>
                            )}
                          </Badge>
                        </li>
                      );
                    })}
                  </ul>

                  {space.groupMemberships.length > 0 && (
                    <>
                      <p className="text-xs text-muted-foreground mt-3 mb-1">
                        Groups
                      </p>
                      <ul className="flex flex-wrap gap-1 mb-3">
                        {space.groupMemberships.map((groupMembership) => (
                          <li key={groupMembership["@id"]}>
                            <Badge variant="outline">
                              {groupMembership.userGroup.title ?? groupMembership.userGroup.id}
                            </Badge>
                          </li>
                        ))}
                      </ul>
                    </>
                  )}

                  {isAdmin && !space.isPersonal && (
                    <form
                      onSubmit={handleAddMember}
                      className="flex items-center gap-2"
                      data-testid="add-space-member-form"
                    >
                      <Input
                        type="email"
                        value={newMemberEmail}
                        onChange={(e) => setNewMemberEmail(e.target.value)}
                        placeholder="member@example.com"
                        aria-label="New member email"
                        required
                        className="flex-1"
                      />
                      <Button
                        type="submit"
                        size="sm"
                        disabled={isAddingMember || !newMemberEmail.trim()}
                      >
                        {isAddingMember ? "Sending…" : "Add"}
                      </Button>
                    </form>
                  )}

                  {memberMessage && (
                    <p
                      role="alert"
                      className={
                        "mt-2 text-sm " +
                        (memberMessage.kind === "success"
                          ? "text-muted-foreground"
                          : "text-destructive")
                      }
                    >
                      {memberMessage.text}
                    </p>
                  )}
                </CardContent>
              </Card>

              {isAdmin && invites.length > 0 && (
                <Card className="mb-6">
                  <CardContent className="pt-6">
                    <h2 className="text-lg font-semibold mb-3">
                      Pending invitations
                    </h2>
                    <ul className="space-y-2" data-testid="space-pending-invites">
                      {invites.map((invite) => (
                        <li
                          key={invite.id}
                          className="flex items-center justify-between text-sm"
                          data-testid="space-pending-invite"
                        >
                          <span>
                            <span className="font-medium">{invite.email}</span>{" "}
                            <span className="text-muted-foreground">
                              · invited by {invite.invitedBy}
                            </span>
                          </span>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => handleRevokeInvite(invite)}
                          >
                            Revoke
                          </Button>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              )}

              {isAdmin && !space.isPersonal && (
                <Card>
                  <CardContent className="pt-6">
                    <h2 className="text-lg font-semibold mb-2">Danger zone</h2>
                    <p className="text-sm text-muted-foreground mb-3">
                      Deleting a space removes every project, discussion,
                      and custom field that lives in it. This can&apos;t be
                      undone.
                    </p>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={handleDeleteSpace}
                    >
                      Delete space
                    </Button>
                  </CardContent>
                </Card>
              )}
                </TabsContent>

                <TabsContent value="projects" className="mt-0">
                  <SpaceProjectsList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "projects"}
                  />
                </TabsContent>

                <TabsContent value="discussions" className="mt-0">
                  <SpaceDiscussionsList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "discussions"}
                  />
                </TabsContent>

                <TabsContent value="pages" className="mt-0">
                  <SpacePagesList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "pages"}
                  />
                </TabsContent>

                <TabsContent value="tasks" className="mt-0">
                  <SpaceTasksList
                    spaceIri={space["@id"]}
                    enabled={activeTab === "tasks"}
                  />
                </TabsContent>

                <TabsContent value="files" className="mt-0">
                  <Card data-testid="space-attachments">
                    <CardContent className="pt-6">
                      <AttachmentsPanel
                        taskTitle={space.name}
                        attachments={space.attachments ?? []}
                        // Only admins can edit the space; non-admins
                        // hit the same `Patch` security expression
                        // that powers metadata edits.
                        canDeleteAll={isAdmin}
                        onAttach={handleAttach}
                        onDetach={handleDetach}
                      />
                    </CardContent>
                  </Card>
                </TabsContent>
              </Tabs>
            </>
          )}
        </div>
      </main>
    </>
  );
};

export default SpaceDetail;
