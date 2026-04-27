import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "../../contexts/AuthContext";
import { ENTRYPOINT } from "../../config/entrypoint";
import MarkdownEditor from "../../components/editor/MarkdownEditor";
import MarkdownView from "../../components/editor/MarkdownView";

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
      <div className="min-h-screen flex items-center justify-center bg-gray-50">
        <p className="text-gray-500">Loading...</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Groups - Aura</title>
      </Head>
      <div className="min-h-screen bg-gray-50 px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold text-black mb-6">Groups</h1>

          <form
            onSubmit={handleCreate}
            className="bg-white rounded-lg shadow-card p-6 mb-6 space-y-4"
          >
            <div>
              <label htmlFor="title" className="block text-sm font-medium text-gray-700">
                Title
              </label>
              <input
                id="title"
                type="text"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                required
                maxLength={255}
                className="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-500"
              />
            </div>
            <div>
              <label htmlFor="description" className="block text-sm font-medium text-gray-700 mb-1">
                Description <span className="text-gray-400">(optional)</span>
              </label>
              <MarkdownEditor
                key={editorResetKey}
                id="description"
                ariaLabel="Description"
                value={description}
                onChange={setDescription}
              />
            </div>
            <div data-testid="invite-members-section">
              <label htmlFor="invite-email" className="block text-sm font-medium text-gray-700 mb-1">
                Invite members <span className="text-gray-400">(optional)</span>
              </label>
              <div className="flex items-center gap-2">
                <input
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
                  className="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500"
                />
                <button
                  type="button"
                  onClick={queueInvite}
                  disabled={!inviteEmail.trim()}
                  className="bg-gray-200 text-gray-700 py-2 px-3 rounded-md text-sm font-medium hover:bg-gray-300 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  Add
                </button>
              </div>
              {inviteError && (
                <p role="alert" className="mt-1 text-sm text-red-600">
                  {inviteError}
                </p>
              )}
              {pendingInvites.length > 0 && (
                <ul
                  className="mt-2 flex flex-wrap items-center gap-1"
                  data-testid="pending-invites"
                >
                  {pendingInvites.map((email) => (
                    <li
                      key={email}
                      className="inline-flex items-center gap-1 bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded"
                      data-testid="pending-invite"
                    >
                      <span>{email}</span>
                      <button
                        type="button"
                        onClick={() => removeInvite(email)}
                        aria-label={`Remove ${email} from invites`}
                        className="ml-1 text-gray-500 hover:text-red-600 leading-none bg-transparent border-0 cursor-pointer text-base"
                      >
                        ×
                      </button>
                    </li>
                  ))}
                </ul>
              )}
              <p className="mt-1 text-xs text-gray-500">
                You&apos;ll be added as the owner automatically. Existing users join immediately;
                others get an email invite to sign up.
              </p>
            </div>
            <button
              type="submit"
              disabled={isSubmitting || !title.trim()}
              className="bg-cyan-700 text-white py-2 px-4 rounded-md font-semibold hover:bg-cyan-800 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isSubmitting ? "Adding..." : "Add Group"}
            </button>
          </form>

          {error && (
            <div
              role="alert"
              className="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-2 rounded"
            >
              {error}
            </div>
          )}

          {isLoading ? (
            <p className="text-gray-500">Loading groups...</p>
          ) : groups.length === 0 ? (
            <p className="text-gray-500 bg-white rounded-lg shadow-card p-6">
              No groups yet. Create one above to organize people.
            </p>
          ) : (
            <ul className="space-y-2" data-testid="group-list">
              {groups.map((group) => {
                const isOwner = group.owner.id === user?.id;
                return (
                  <li
                    key={group["@id"]}
                    className="bg-white rounded-lg shadow-card p-4"
                    data-testid="group-item"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <h2 className="font-semibold text-black">
                        <Link
                          href={`/groups/${group.id}`}
                          className="text-cyan-700 hover:text-cyan-900 no-underline"
                        >
                          {group.title}
                        </Link>
                      </h2>
                      {isOwner && (
                        <div className="flex items-center gap-3 shrink-0">
                          <button
                            onClick={() => handleDelete(group)}
                            aria-label={`Delete "${group.title}"`}
                            className="text-red-600 hover:text-red-700 text-sm font-medium bg-transparent border-0 cursor-pointer"
                          >
                            Delete
                          </button>
                        </div>
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
                        <span className="text-xs text-gray-500">Members:</span>
                        {group.members.map((member) => (
                          <span
                            key={member["@id"]}
                            className="inline-block px-2 py-0.5 rounded text-xs bg-gray-100 text-gray-700"
                            data-testid="group-member"
                          >
                            {member.email}
                          </span>
                        ))}
                      </div>
                    )}
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
