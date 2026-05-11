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

  // Move-to-project (#182). Pulls the caller's accessible projects so
  // the dropdown only lists targets they can actually post in; the
  // server enforces the same rule.
  const [accessibleProjects, setAccessibleProjects] = useState<Project[]>([]);
  const [moveTargetIri, setMoveTargetIri] = useState("");
  const [isMoving, setIsMoving] = useState(false);
  const [moveMessage, setMoveMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  // Copy-to-project (#182). Shares the picker with Move; reuses
  // moveMessage for errors.
  const [isCopying, setIsCopying] = useState(false);

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
      const [projectRes, discussionRes, projectsRes] = await Promise.all([
        fetch(`${ENTRYPOINT}/projects/${encodeURIComponent(projectId)}`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        fetch(`${ENTRYPOINT}/discussions/${encodeURIComponent(did)}`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
        // Move-target picker needs the user's accessible projects.
        // The access extension trims this to projects the caller can
        // read; the server-side move guard re-checks on POST anyway.
        fetch(`${ENTRYPOINT}/projects?itemsPerPage=100`, {
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
      if (projectsRes.ok) {
        const projectsData: { member?: Project[]; "hydra:member"?: Project[] } =
          await projectsRes.json();
        setAccessibleProjects(
          projectsData.member ?? projectsData["hydra:member"] ?? [],
        );
      }
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

  const handleMove = async () => {
    if (!discussion || !moveTargetIri) return;
    setIsMoving(true);
    setMoveMessage(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/discussions/${encodeURIComponent(discussion.id)}/move`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ project: moveTargetIri }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to move discussion.",
        );
      }
      // The discussion's `project` FK changes, so its canonical URL
      // changes too — bounce to the new project's discussion detail
      // path. moveMessage gets a chance to render briefly via the
      // router transition.
      const target = accessibleProjects.find((p) => p["@id"] === moveTargetIri);
      const targetId = target?.id ?? moveTargetIri.split("/").pop();
      setMoveMessage({
        text: data.moved
          ? `Moved to "${target?.title ?? "the selected project"}".`
          : "Already in that project.",
        kind: "success",
      });
      if (data.moved && targetId) {
        void router.replace(
          `/projects/${targetId}/discussions/${discussion.id}`,
        );
      }
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to move discussion.",
        kind: "error",
      });
    } finally {
      setIsMoving(false);
    }
  };

  const handleCopy = async () => {
    if (!discussion) return;
    setIsCopying(true);
    setMoveMessage(null);
    try {
      // Empty body = clone into the source project; specifying a
      // project uses the picker's current selection. The server
      // accepts both shapes.
      const body = moveTargetIri ? { project: moveTargetIri } : {};
      const res = await fetch(
        `${ENTRYPOINT}/discussions/${encodeURIComponent(discussion.id)}/copy`,
        {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(body),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to copy discussion.",
        );
      }
      // Drop the user on the freshly-cloned discussion. The target
      // project owns it, so we route through that project's
      // discussion sub-path.
      const targetProjectIri = data.project as string | undefined;
      const targetProjectId = targetProjectIri?.split("/").pop();
      if (data.id && targetProjectId) {
        await router.push(
          `/projects/${targetProjectId}/discussions/${data.id}`,
        );
      }
    } catch (err) {
      setMoveMessage({
        text: err instanceof Error ? err.message : "Failed to copy discussion.",
        kind: "error",
      });
    } finally {
      setIsCopying(false);
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

                  {/* Move-to-project (#182). Author-or-admin only —
                      same bar as edit/delete. Hidden when the user
                      has only the current project (nothing to move
                      to). The server re-checks target-project
                      access on POST. */}
                  {(() => {
                    const currentUserIri = user ? `/users/${user.id}` : null;
                    const isAuthor =
                      currentUserIri === discussion.author["@id"];
                    const isOwner = user.email === project.owner.email;
                    const canMove = isAuthor || isOwner;
                    const otherProjects = accessibleProjects.filter(
                      (p) => p["@id"] !== project["@id"],
                    );
                    // Move requires another project; Copy works
                    // in-place too. Hide the whole row only when
                    // the caller can't move/copy at all OR there's
                    // genuinely nothing to do (no other project AND
                    // no canMove).
                    if (!canMove) return null;
                    return (
                      <div
                        className="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t"
                        data-testid="discussion-move-form"
                      >
                        <Label
                          htmlFor="discussion-move-target"
                          className="text-xs text-muted-foreground"
                        >
                          Move or copy to project
                        </Label>
                        <select
                          id="discussion-move-target"
                          value={moveTargetIri}
                          onChange={(e) => setMoveTargetIri(e.target.value)}
                          className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                          data-testid="discussion-move-select"
                        >
                          <option value="">Pick a project…</option>
                          {otherProjects.map((p) => (
                            <option key={p["@id"]} value={p["@id"]}>
                              {p.title}
                            </option>
                          ))}
                        </select>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => void handleMove()}
                          disabled={!moveTargetIri || isMoving || isCopying}
                          data-testid="discussion-move-submit"
                        >
                          {isMoving ? "Moving…" : "Move"}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => void handleCopy()}
                          disabled={isMoving || isCopying}
                          data-testid="discussion-copy-submit"
                          title={
                            moveTargetIri
                              ? "Copy this discussion into the selected project"
                              : "Copy this discussion into the current project"
                          }
                        >
                          {isCopying ? "Copying…" : "Copy"}
                        </Button>
                        {moveMessage && (
                          <span
                            role="alert"
                            className={
                              "text-xs " +
                              (moveMessage.kind === "success"
                                ? "text-muted-foreground"
                                : "text-destructive")
                            }
                          >
                            {moveMessage.text}
                          </span>
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
