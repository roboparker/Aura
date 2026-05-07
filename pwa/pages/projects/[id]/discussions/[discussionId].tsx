import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Lock, Pencil, Pin, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  CATEGORY_LABEL,
  errorMessage,
  formatRelative,
  type Discussion,
  type DiscussionCategory,
} from "@/components/discussions/DiscussionsPanel";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface ProjectMember {
  "@id": string;
  id: string;
  email: string;
}

interface Project {
  "@id": string;
  id: string;
  title: string;
  owner: ProjectMember;
}

const DiscussionDetailPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id, discussionId } = router.query;
  const projectId = typeof id === "string" ? id : null;
  const did = typeof discussionId === "string" ? discussionId : null;

  const [project, setProject] = useState<Project | null>(null);
  const [discussion, setDiscussion] = useState<Discussion | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Edit state
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editBody, setEditBody] = useState("");
  const [editCategory, setEditCategory] = useState<DiscussionCategory>("general");
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!projectId || !did) return;
    setError(null);
    setIsLoading(true);
    try {
      const [projectRes, discussionRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/projects/${encodeURIComponent(projectId)}`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/discussions/${encodeURIComponent(did)}`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
      ]);
      if (
        projectRes.status === 404 ||
        projectRes.status === 403 ||
        discussionRes.status === 404 ||
        discussionRes.status === 403
      ) {
        setNotFound(true);
        return;
      }
      if (!projectRes.ok) throw new Error("Failed to load project.");
      if (!discussionRes.ok) throw new Error("Failed to load discussion.");
      const projectData: Project = await projectRes.json();
      const discussionData: Discussion = await discussionRes.json();
      setProject(projectData);
      setDiscussion(discussionData);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [projectId, did]);

  useEffect(() => {
    if (isAuthenticated && projectId && did) void load();
  }, [isAuthenticated, projectId, did, load]);

  const startEdit = () => {
    if (!discussion) return;
    setEditTitle(discussion.title);
    setEditBody(discussion.body);
    setEditCategory(discussion.category);
    setEditorKey((k) => k + 1);
    setEditing(true);
    setEditError(null);
  };

  const cancelEdit = () => {
    setEditing(false);
    setEditError(null);
  };

  const patch = async (
    body: Partial<
      Pick<Discussion, "title" | "body" | "category" | "isPinned" | "isLocked">
    >,
  ): Promise<Discussion | null> => {
    if (!discussion) return null;
    const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: {
        "Content-Type": "application/merge-patch+json",
        Accept: "application/ld+json",
      },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error(await errorMessage(res));
    }
    const updated: Discussion = await res.json();
    setDiscussion(updated);
    return updated;
  };

  const saveEdit = async () => {
    const trimTitle = editTitle.trim();
    const trimBody = editBody.trim();
    if (!trimTitle || !trimBody) return;
    setSavingEdit(true);
    setEditError(null);
    try {
      await patch({
        title: trimTitle,
        body: editBody,
        category: editCategory,
      });
      setEditing(false);
    } catch (err) {
      setEditError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSavingEdit(false);
    }
  };

  const togglePin = async () => {
    if (!discussion) return;
    setBusy(true);
    setError(null);
    try {
      await patch({ isPinned: !discussion.isPinned });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update.");
    } finally {
      setBusy(false);
    }
  };

  const toggleLock = async () => {
    if (!discussion) return;
    setBusy(true);
    setError(null);
    try {
      await patch({ isLocked: !discussion.isLocked });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update.");
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!discussion || !project) return;
    if (!window.confirm(`Delete discussion "${discussion.title}"?`)) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      void router.replace(`/projects/${project.id}/discussions`);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
      setBusy(false);
    }
  };

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>
          {discussion ? `${discussion.title} - Aura` : "Discussion - Aura"}
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-3xl mx-auto px-4 py-8 space-y-4">
          {notFound ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  Discussion not found, or you don&apos;t have access.
                </p>
                {projectId && (
                  <Button asChild variant="link" className="px-0">
                    <Link href={`/projects/${projectId}/discussions`}>
                      Back to discussions
                    </Link>
                  </Button>
                )}
              </CardContent>
            </Card>
          ) : isLoading || !project || !discussion ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <>
              <Button
                asChild
                variant="link"
                size="sm"
                className="px-0 h-auto"
              >
                <Link
                  href={`/projects/${project.id}/discussions`}
                  data-testid="discussion-back-link"
                >
                  <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Discussions
                </Link>
              </Button>

              <Card data-testid="discussion-detail">
                <CardContent className="pt-6 space-y-4">
                  <div className="flex items-start gap-3">
                    <UserAvatar user={discussion.author} size="sm" />
                    <div className="min-w-0 flex-1 space-y-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <h1
                          className="text-xl font-semibold"
                          data-testid="discussion-detail-title"
                        >
                          {discussion.title}
                        </h1>
                        {discussion.isPinned && (
                          <Badge variant="secondary" className="gap-1">
                            <Pin className="h-3 w-3" /> Pinned
                          </Badge>
                        )}
                        {discussion.isLocked && (
                          <Badge variant="secondary" className="gap-1">
                            <Lock className="h-3 w-3" /> Locked
                          </Badge>
                        )}
                        <Badge variant="outline">
                          {CATEGORY_LABEL[discussion.category]}
                        </Badge>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        {displayName(discussion.author)} ·{" "}
                        {formatRelative(discussion.createdAt)}
                        {discussion.updatedAt && " · edited"}
                      </p>
                    </div>
                  </div>

                  {!editing && (
                    <MarkdownView
                      source={discussion.body}
                      className="text-sm"
                    />
                  )}

                  {editing && (
                    <div className="space-y-3">
                      <div className="space-y-1.5">
                        <Label htmlFor="edit-title">Title</Label>
                        <Input
                          id="edit-title"
                          value={editTitle}
                          onChange={(e) => setEditTitle(e.target.value)}
                          maxLength={200}
                          required
                        />
                      </div>
                      <div className="space-y-1.5">
                        <Label htmlFor="edit-category">Category</Label>
                        <select
                          id="edit-category"
                          value={editCategory}
                          onChange={(e) =>
                            setEditCategory(
                              e.target.value as DiscussionCategory,
                            )
                          }
                          className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                          {(
                            Object.keys(CATEGORY_LABEL) as DiscussionCategory[]
                          ).map((cat) => (
                            <option key={cat} value={cat}>
                              {CATEGORY_LABEL[cat]}
                            </option>
                          ))}
                        </select>
                      </div>
                      <MarkdownEditor
                        key={editorKey}
                        ariaLabel="Edit discussion body"
                        value={editBody}
                        onChange={setEditBody}
                      />
                      {editError && (
                        <Alert variant="destructive">
                          <AlertDescription>{editError}</AlertDescription>
                        </Alert>
                      )}
                      <div className="flex gap-2">
                        <Button
                          type="button"
                          size="sm"
                          onClick={() => void saveEdit()}
                          disabled={
                            savingEdit ||
                            !editTitle.trim() ||
                            !editBody.trim()
                          }
                          data-testid="discussion-save-edit"
                        >
                          {savingEdit ? "Saving…" : "Save"}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={cancelEdit}
                          disabled={savingEdit}
                        >
                          Cancel
                        </Button>
                      </div>
                    </div>
                  )}

                  {error && (
                    <Alert variant="destructive">
                      <AlertDescription>{error}</AlertDescription>
                    </Alert>
                  )}

                  {!editing &&
                    (() => {
                      const currentUserIri = `/users/${user.id}`;
                      const isAuthor =
                        currentUserIri === discussion.author["@id"];
                      const isOwner = user.email === project.owner.email;
                      const canEdit = isAuthor;
                      const canDelete = isAuthor || isOwner;
                      const canModerate = isOwner;
                      if (!canEdit && !canDelete && !canModerate) return null;
                      return (
                        <div className="flex flex-wrap gap-2">
                          {canEdit && (
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              onClick={startEdit}
                              disabled={busy}
                              data-testid="discussion-edit"
                            >
                              <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
                            </Button>
                          )}
                          {canModerate && (
                            <>
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => void togglePin()}
                                disabled={busy}
                                data-testid="discussion-toggle-pin"
                              >
                                <Pin className="h-3.5 w-3.5 mr-1" />
                                {discussion.isPinned ? "Unpin" : "Pin"}
                              </Button>
                              <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => void toggleLock()}
                                disabled={busy}
                                data-testid="discussion-toggle-lock"
                              >
                                <Lock className="h-3.5 w-3.5 mr-1" />
                                {discussion.isLocked ? "Unlock" : "Lock"}
                              </Button>
                            </>
                          )}
                          {canDelete && (
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              onClick={() => void remove()}
                              disabled={busy}
                              className="text-destructive hover:text-destructive"
                              data-testid="discussion-delete"
                            >
                              <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                            </Button>
                          )}
                        </div>
                      );
                    })()}
                </CardContent>
              </Card>
            </>
          )}
        </div>
      </main>
    </>
  );
};

export default DiscussionDetailPage;
