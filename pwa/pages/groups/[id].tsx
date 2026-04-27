import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { X } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";

interface Member {
  "@id": string;
  id: string;
  email: string;
}

interface Group {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: Member;
  members: Member[];
}

interface PendingInvite {
  id: string;
  email: string;
  invitedBy: string;
  createdAt: string;
  expiresAt: string;
}

const GroupDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id } = router.query;
  const groupId = typeof id === "string" ? id : null;

  const [group, setGroup] = useState<Group | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [isEditing, setIsEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");

  // Owner-only forms
  const [newMemberEmail, setNewMemberEmail] = useState("");
  const [isAddingMember, setIsAddingMember] = useState(false);
  const [memberError, setMemberError] = useState<string | null>(null);
  const [memberInfo, setMemberInfo] = useState<string | null>(null);

  const [pendingInvites, setPendingInvites] = useState<PendingInvite[]>([]);

  const [transferEmail, setTransferEmail] = useState("");
  const [isTransferring, setIsTransferring] = useState(false);
  const [transferError, setTransferError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadPendingInvites = useCallback(async () => {
    if (!groupId) return;
    // The endpoint is owner-only and returns 403 to non-owner members. We
    // try optimistically and silently swallow forbidden so non-owners just
    // see no pending-invites section.
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${encodeURIComponent(groupId)}/invites`,
        {
          credentials: "include",
          headers: { Accept: "application/json" },
        },
      );
      if (!res.ok) {
        setPendingInvites([]);
        return;
      }
      const data: { invites: PendingInvite[] } = await res.json();
      setPendingInvites(data.invites ?? []);
    } catch {
      setPendingInvites([]);
    }
  }, [groupId]);

  const load = useCallback(async () => {
    if (!groupId) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${encodeURIComponent(groupId)}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (res.status === 404 || res.status === 403) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load group.");
      const data: Group = await res.json();
      setGroup(data);
      setEditTitle(data.title);
      setEditDescription(data.description ?? "");
      await loadPendingInvites();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load group.");
    } finally {
      setIsLoading(false);
    }
  }, [groupId, loadPendingInvites]);

  useEffect(() => {
    if (isAuthenticated && groupId) {
      load();
    }
  }, [isAuthenticated, groupId, load]);

  const isOwner = !!group && !!user && group.owner.id === user.id;

  const handleSaveEdit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!group || !editTitle.trim()) return;

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          title: editTitle.trim(),
          description: editDescription.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to update group.",
        );
      }
      setIsEditing(false);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update group.");
    }
  };

  const handleAddMember = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!group || !newMemberEmail.trim()) return;

    const submittedEmail = newMemberEmail.trim();
    setIsAddingMember(true);
    setMemberError(null);
    setMemberInfo(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups/${group.id}/members`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: submittedEmail }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to add member.");
      }
      const data: { status?: "added" | "invited" } = await res.json().catch(() => ({}));
      if (data.status === "invited") {
        setMemberInfo(
          `${submittedEmail} doesn't have an Aura account yet — we sent them an invitation email.`,
        );
      }
      setNewMemberEmail("");
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to add member.");
    } finally {
      setIsAddingMember(false);
    }
  };

  const handleRevokeInvite = async (invite: PendingInvite) => {
    if (!group) return;
    if (!window.confirm(`Revoke the invitation sent to ${invite.email}?`)) {
      return;
    }

    setMemberError(null);
    setMemberInfo(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/groups/${group.id}/invites/${invite.id}`,
        {
          method: "DELETE",
          credentials: "include",
        },
      );
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.error || "Failed to revoke invite.");
      }
      await loadPendingInvites();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to revoke invite.");
    }
  };

  const handleRemoveMember = async (member: Member) => {
    if (!group) return;
    // Removing the owner from the member list is a UI footgun — block it
    // and steer the owner toward Transfer Ownership instead.
    if (member.id === group.owner.id) {
      setMemberError("The owner can't be removed. Transfer ownership first.");
      return;
    }
    if (!window.confirm(`Remove ${member.email} from this group?`)) {
      return;
    }

    setMemberError(null);
    try {
      const remaining = group.members
        .filter((m) => m["@id"] !== member["@id"])
        .map((m) => m["@id"]);
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ members: remaining }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.detail || data["hydra:description"] || "Failed to remove member.");
      }
      await load();
    } catch (err) {
      setMemberError(err instanceof Error ? err.message : "Failed to remove member.");
    }
  };

  const handleTransferOwnership = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!group || !transferEmail.trim()) return;

    const target = group.members.find(
      (m) => m.email.toLowerCase() === transferEmail.trim().toLowerCase(),
    );
    if (!target) {
      setTransferError("Pick a member of this group as the new owner.");
      return;
    }
    if (target.id === group.owner.id) {
      setTransferError("That user is already the owner.");
      return;
    }
    if (
      !window.confirm(
        `Transfer ownership to ${target.email}? You will become a regular member and lose edit/delete rights.`,
      )
    ) {
      return;
    }

    setIsTransferring(true);
    setTransferError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ owner: target["@id"] }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(data.detail || data["hydra:description"] || "Failed to transfer ownership.");
      }
      setTransferEmail("");
      await load();
    } catch (err) {
      setTransferError(err instanceof Error ? err.message : "Failed to transfer ownership.");
    } finally {
      setIsTransferring(false);
    }
  };

  const handleDelete = async () => {
    if (!group) return;
    if (!window.confirm(`Delete group "${group.title}"? This removes it for all members.`)) {
      return;
    }

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error("Failed to delete group.");
      router.push("/groups");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete group.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading...</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="min-h-screen bg-muted px-4 py-12">
        <Card className="max-w-2xl mx-auto">
          <CardContent className="pt-6">
            <h1 className="text-xl font-bold mb-2">Group not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not be a member.
            </p>
            <Link href="/groups" className="text-cyan-700 font-medium">
              Back to groups
            </Link>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{group ? `${group.title} - Aura` : "Group - Aura"}</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <Link
            href="/groups"
            className="inline-block text-sm text-cyan-700 hover:text-cyan-900 mb-3 no-underline"
          >
            ← All groups
          </Link>

          {isLoading || !group ? (
            <p className="text-muted-foreground">Loading group...</p>
          ) : (
            <>
              <Card className="mb-6">
                <CardContent className="pt-6">
                  {isEditing && isOwner ? (
                    <form
                      onSubmit={handleSaveEdit}
                      className="space-y-3"
                      data-testid="edit-group-form"
                    >
                      <Input
                        type="text"
                        value={editTitle}
                        onChange={(e) => setEditTitle(e.target.value)}
                        required
                        maxLength={255}
                        aria-label="Title"
                      />
                      <MarkdownEditor
                        ariaLabel="Description"
                        value={editDescription}
                        onChange={setEditDescription}
                      />
                      <div className="flex gap-2">
                        <Button type="submit" size="sm">
                          Save
                        </Button>
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          onClick={() => {
                            setIsEditing(false);
                            setEditTitle(group.title);
                            setEditDescription(group.description ?? "");
                          }}
                        >
                          Cancel
                        </Button>
                      </div>
                    </form>
                  ) : (
                    <>
                      <div className="flex items-start justify-between gap-3">
                        <h1 className="text-2xl font-bold mb-2">{group.title}</h1>
                        {isOwner && (
                          <div className="flex items-center gap-1 shrink-0">
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => setIsEditing(true)}
                            >
                              Edit
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={handleDelete}
                              className="text-destructive hover:text-destructive"
                            >
                              Delete
                            </Button>
                          </div>
                        )}
                      </div>
                      {group.description && (
                        <MarkdownView source={group.description} className="mb-3" />
                      )}
                    </>
                  )}

                  <p className="mt-3 text-xs text-muted-foreground">
                    Owner: <span data-testid="group-owner">{group.owner.email}</span>
                  </p>

                  <div className="mt-3">
                    <p className="text-xs text-muted-foreground mb-1">Members</p>
                    <ul className="flex flex-wrap items-center gap-1" data-testid="member-list">
                      {group.members.map((member) => (
                        <li key={member["@id"]} data-testid="member-pill">
                          <Badge variant="muted" className="gap-1">
                            <span>{member.email}</span>
                            {isOwner && member.id !== group.owner.id && (
                              <button
                                type="button"
                                onClick={() => handleRemoveMember(member)}
                                aria-label={`Remove ${member.email}`}
                                className="ml-0.5 text-gray-500 hover:text-destructive bg-transparent border-0 cursor-pointer"
                              >
                                <X className="h-3 w-3" />
                              </button>
                            )}
                          </Badge>
                        </li>
                      ))}
                    </ul>

                    {isOwner && (
                      <>
                        <form
                          onSubmit={handleAddMember}
                          className="mt-2 flex items-center gap-2"
                          data-testid="add-member-form"
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
                            {isAddingMember ? "Adding..." : "Add"}
                          </Button>
                        </form>
                        {memberError && (
                          <p role="alert" className="mt-2 text-sm text-destructive">
                            {memberError}
                          </p>
                        )}
                        {memberInfo && (
                          <Alert
                            variant="info"
                            className="mt-2"
                            role="status"
                            data-testid="member-info"
                          >
                            <AlertDescription>{memberInfo}</AlertDescription>
                          </Alert>
                        )}
                      </>
                    )}
                  </div>

                  {isOwner && pendingInvites.length > 0 && (
                    <div className="mt-4" data-testid="pending-invites-section">
                      <p className="text-xs text-muted-foreground mb-1">Pending invites</p>
                      <ul className="flex flex-wrap items-center gap-1">
                        {pendingInvites.map((invite) => (
                          <li key={invite.id} data-testid="pending-invite-pill">
                            <Badge
                              className="gap-1 bg-amber-100 text-amber-800 border-transparent"
                              variant="outline"
                            >
                              <span>{invite.email}</span>
                              <button
                                type="button"
                                onClick={() => handleRevokeInvite(invite)}
                                aria-label={`Revoke invite to ${invite.email}`}
                                className="ml-0.5 text-amber-700 hover:text-destructive bg-transparent border-0 cursor-pointer"
                              >
                                <X className="h-3 w-3" />
                              </button>
                            </Badge>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}

                  {isOwner && group.members.length > 1 && (
                    <div className="mt-6">
                      <Separator className="mb-4" />
                      <p className="text-xs text-muted-foreground mb-1">Transfer ownership</p>
                      <form
                        onSubmit={handleTransferOwnership}
                        className="flex items-center gap-2"
                        data-testid="transfer-ownership-form"
                      >
                        <Input
                          type="email"
                          value={transferEmail}
                          onChange={(e) => setTransferEmail(e.target.value)}
                          placeholder="member@example.com"
                          aria-label="New owner email"
                          required
                          className="flex-1"
                        />
                        <Button
                          type="submit"
                          variant="warning"
                          size="sm"
                          disabled={isTransferring || !transferEmail.trim()}
                        >
                          {isTransferring ? "Transferring..." : "Transfer"}
                        </Button>
                      </form>
                      {transferError && (
                        <p role="alert" className="mt-2 text-sm text-destructive">
                          {transferError}
                        </p>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>

              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default GroupDetail;
