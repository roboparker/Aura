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
import { Label } from "@/components/ui/label";

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

interface GroupCollection {
  member?: Group[];
  "hydra:member"?: Group[];
}

const Groups = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [groups, setGroups] = useState<Group[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editorResetKey, setEditorResetKey] = useState(0);

  // Pending member invites collected before submit. We resolve them server-side
  // (POST /groups/{id}/members) once the group exists, since that's where the
  // email -> user lookup lives.
  const [inviteEmail, setInviteEmail] = useState("");
  const [pendingInvites, setPendingInvites] = useState<string[]>([]);
  const [inviteError, setInviteError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.push("/signin");
    }
  }, [authLoading, isAuthenticated, router]);

  const loadGroups = useCallback(async () => {
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) {
        throw new Error("Failed to load groups.");
      }
      const data: GroupCollection = await res.json();
      setGroups(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load groups.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) {
      loadGroups();
    }
  }, [isAuthenticated, loadGroups]);

  const queueInvite = () => {
    const email = inviteEmail.trim().toLowerCase();
    if (!email) return;
    if (email === user?.email.toLowerCase()) {
      setInviteError("You're already added as the owner.");
      return;
    }
    if (pendingInvites.includes(email)) {
      setInviteError("That email is already in the invite list.");
      return;
    }
    setPendingInvites((prev) => [...prev, email]);
    setInviteEmail("");
    setInviteError(null);
  };

  const removeInvite = (email: string) => {
    setPendingInvites((prev) => prev.filter((e) => e !== email));
  };

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;

    setIsSubmitting(true);
    setError(null);
    setInviteError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/groups`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: title.trim(),
          description: description.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description || data.detail || data["hydra:description"] || "Failed to create group.",
        );
      }
      const created: { id: string } = await res.json();

      // Fan out member adds. The API handles existing users (added now)
      // and unknown emails (an invite is emailed). We surface counts so
      // the owner can see what landed; only network/validation errors
      // become a "failed" list.
      let addedCount = 0;
      let invitedCount = 0;
      const failedInvites: string[] = [];
      for (const email of pendingInvites) {
        const inviteRes = await fetch(`${ENTRYPOINT}/groups/${created.id}/members`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ email }),
        });
        if (!inviteRes.ok) {
          failedInvites.push(email);
          continue;
        }
        const data = await inviteRes.json().catch(() => ({}));
        if (data.status === "added") addedCount += 1;
        else if (data.status === "invited") invitedCount += 1;
      }

      setTitle("");
      setDescription("");
      setPendingInvites([]);
      setInviteEmail("");
      setEditorResetKey((k) => k + 1);
      if (failedInvites.length > 0) {
        const summary: string[] = [];
        if (addedCount > 0) summary.push(`${addedCount} added`);
        if (invitedCount > 0) summary.push(`${invitedCount} invited`);
        const summaryText = summary.length > 0 ? ` (${summary.join(", ")} succeeded)` : "";
        setError(
          `Group created, but couldn't reach: ${failedInvites.join(", ")}${summaryText}.`,
        );
      }
      await loadGroups();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create group.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleDelete = async (group: Group) => {
    if (
      !window.confirm(
        `Delete group "${group.title}"? This removes it for all members.`,
      )
    ) {
      return;
    }

    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${group["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error("Failed to delete group.");
      }
      await loadGroups();
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

  return (
    <>
      <Head>
        <title>Groups - Aura</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold mb-6">Groups</h1>

          <Card className="mb-6">
            <CardContent className="pt-6">
              <form onSubmit={handleCreate} className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="title">Title</Label>
                  <Input
                    id="title"
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    required
                    maxLength={255}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="description">
                    Description{" "}
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  </Label>
                  <MarkdownEditor
                    key={editorResetKey}
                    id="description"
                    ariaLabel="Description"
                    value={description}
                    onChange={setDescription}
                  />
                </div>
                <div className="space-y-1.5" data-testid="invite-members-section">
                  <Label htmlFor="invite-email">
                    Invite members{" "}
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  </Label>
                  <div className="flex items-center gap-2">
                    <Input
                      id="invite-email"
                      type="email"
                      value={inviteEmail}
                      onChange={(e) => setInviteEmail(e.target.value)}
                      onKeyDown={(e) => {
                        // Enter inside a nested input would otherwise submit the
                        // outer form; treat it as "add to invite list" instead.
                        if (e.key === "Enter") {
                          e.preventDefault();
                          queueInvite();
                        }
                      }}
                      placeholder="member@example.com"
                      className="flex-1"
                    />
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={queueInvite}
                      disabled={!inviteEmail.trim()}
                    >
                      Add
                    </Button>
                  </div>
                  {inviteError && (
                    <p role="alert" className="text-sm text-destructive">
                      {inviteError}
                    </p>
                  )}
                  {pendingInvites.length > 0 && (
                    <ul
                      className="flex flex-wrap items-center gap-1"
                      data-testid="pending-invites"
                    >
                      {pendingInvites.map((email) => (
                        <li key={email} data-testid="pending-invite">
                          <Badge variant="muted" className="gap-1">
                            <span>{email}</span>
                            <button
                              type="button"
                              onClick={() => removeInvite(email)}
                              aria-label={`Remove ${email} from invites`}
                              className="ml-0.5 text-gray-500 hover:text-destructive bg-transparent border-0 cursor-pointer"
                            >
                              <X className="h-3 w-3" />
                            </button>
                          </Badge>
                        </li>
                      ))}
                    </ul>
                  )}
                  <p className="text-xs text-muted-foreground">
                    You&apos;ll be added as the owner automatically. Existing users join
                    immediately; others get an email invite to sign up.
                  </p>
                </div>
                <Button type="submit" disabled={isSubmitting || !title.trim()}>
                  {isSubmitting ? "Adding..." : "Add Group"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading ? (
            <p className="text-muted-foreground">Loading groups...</p>
          ) : groups.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  No groups yet. Create one above to organize people.
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-2" data-testid="group-list">
              {groups.map((group) => {
                const isOwner = group.owner.id === user?.id;
                return (
                  <li key={group["@id"]} data-testid="group-item">
                    <Card>
                      <CardContent className="pt-4 pb-4">
                        <div className="flex items-start justify-between gap-3">
                          <h2 className="font-semibold">
                            <Link
                              href={`/groups/${group.id}`}
                              className="text-cyan-700 hover:text-cyan-900 no-underline"
                            >
                              {group.title}
                            </Link>
                          </h2>
                          {isOwner && (
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => handleDelete(group)}
                              aria-label={`Delete "${group.title}"`}
                              className="text-destructive hover:text-destructive"
                            >
                              Delete
                            </Button>
                          )}
                        </div>
                        {group.description && (
                          <MarkdownView source={group.description} className="mt-1" />
                        )}
                        {group.members.length > 0 && (
                          <div
                            className="mt-2 flex flex-wrap items-center gap-1"
                            data-testid="group-members"
                          >
                            <span className="text-xs text-muted-foreground">Members:</span>
                            {group.members.map((member) => (
                              <Badge
                                key={member["@id"]}
                                variant="muted"
                                data-testid="group-member"
                              >
                                {member.email}
                              </Badge>
                            ))}
                          </div>
                        )}
                      </CardContent>
                    </Card>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default Groups;
