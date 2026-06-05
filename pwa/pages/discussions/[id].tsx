import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Lock, MessageSquare, Pencil, Pin, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  CATEGORY_LABEL,
  errorMessage,
  formatRelative,
  type Discussion,
  type DiscussionCategory,
} from "@/components/discussions/DiscussionsPanel";
import CategoryBadge from "@/components/discussions/CategoryBadge";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar from "@/components/user/UserAvatar";
import AvatarStack from "@/components/user/AvatarStack";
import CommentsPanel, {
  type Comment as DiscussionCommentRow,
} from "@/components/common/CommentsPanel";
import { useCommentLiveUpdates } from "@/lib/useCommentLiveUpdates";
import { displayName } from "@/lib/userDisplay";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

// The detail endpoint serializes the parent space as an IRI string; the
// admin lookup happens against the user's already-loaded space list so
// no extra fetch is needed.
type SpaceRef = string | { "@id": string };
type DiscussionDetail = Discussion & { space: SpaceRef };

const spaceIriOf = (d: DiscussionDetail): string =>
  typeof d.space === "string" ? d.space : d.space["@id"];

const findSpace = (spaces: Space[], iri: string): Space | undefined =>
  spaces.find((s) => s["@id"] === iri);

const DiscussionDetailPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { id } = router.query;
  const did = typeof id === "string" ? id : null;

  const [discussion, setDiscussion] = useState<DiscussionDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Comments
  const [comments, setComments] = useState<DiscussionCommentRow[]>([]);

  // Edit state
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editBody, setEditBody] = useState("");
  const [editCategory, setEditCategory] = useState<DiscussionCategory>("general");
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Move / Copy — to any space the caller belongs to.
  const [moveTargetIri, setMoveTargetIri] = useState("");
  const [isMoving, setIsMoving] = useState(false);
  const [isCopying, setIsCopying] = useState(false);
  const [moveMessage, setMoveMessage] = useState<{
    text: string;
    kind: "success" | "error";
  } | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!did) return;
    setError(null);
    setIsLoading(true);
    try {
      const res = await fetch(`${ENTRYPOINT}/discussions/${encodeURIComponent(did)}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (res.status === 404 || res.status === 403) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load discussion.");
      const data: DiscussionDetail = await res.json();
      setDiscussion(data);

      const commentsRes = await fetch(
        `${ENTRYPOINT}/comments?discussion=${encodeURIComponent(data["@id"])}&itemsPerPage=100`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (commentsRes.ok) {
        const collection: {
          member?: DiscussionCommentRow[];
          "hydra:member"?: DiscussionCommentRow[];
        } = await commentsRes.json();
        setComments(collection.member ?? collection["hydra:member"] ?? []);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [did]);

  useEffect(() => {
    if (isAuthenticated && did) void load();
  }, [isAuthenticated, did, load]);

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
  ): Promise<DiscussionDetail | null> => {
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
    if (!res.ok) throw new Error(await errorMessage(res));
    const updated: DiscussionDetail = await res.json();
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
      await patch({ title: trimTitle, body: editBody, category: editCategory });
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
    if (!discussion) return;
    if (!window.confirm(`Delete discussion "${discussion.title}"?`)) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${discussion["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      void router.replace("/discussions");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
      setBusy(false);
    }
  };

  // --- Comments ---
  const handleCreateComment = async (body: string): Promise<void> => {
    if (!discussion) return;
    const res = await fetch(`${ENTRYPOINT}/comments`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/ld+json" },
      body: JSON.stringify({ discussion: discussion["@id"], body }),
    });
    if (!res.ok) throw new Error(await errorMessage(res));
    const created: DiscussionCommentRow = await res.json();
    setComments((prev) =>
      prev.some((c) => c["@id"] === created["@id"]) ? prev : [...prev, created],
    );
  };

  const handleEditComment = async (
    comment: DiscussionCommentRow,
    body: string,
  ): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ body }),
    });
    if (!res.ok) throw new Error(await errorMessage(res));
    const updated: DiscussionCommentRow = await res.json();
    setComments((prev) => prev.map((c) => (c["@id"] === updated["@id"] ? updated : c)));
  };

  const handleDeleteComment = async (
    comment: DiscussionCommentRow,
  ): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) throw new Error(await errorMessage(res));
    setComments((prev) => prev.filter((c) => c["@id"] !== comment["@id"]));
  };

  useCommentLiveUpdates(discussion?.["@id"] ?? null, !!discussion, (event) => {
    if (event.type === "delete") {
      setComments((prev) => prev.filter((c) => c["@id"] !== event.id));
      return;
    }
    const incoming = event.comment as unknown as DiscussionCommentRow;
    setComments((prev) => {
      const idx = prev.findIndex((c) => c["@id"] === incoming["@id"]);
      if (idx === -1) {
        if (event.type !== "create") return prev;
        return [...prev, incoming];
      }
      const next = [...prev];
      next[idx] = incoming;
      return next;
    });
  });

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
          body: JSON.stringify({ space: moveTargetIri }),
        },
      );
      const data = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          data.detail || data.error || data["hydra:description"] || "Failed to move discussion.",
        );
      }
      const target = findSpace(spaces, moveTargetIri);
      setMoveMessage({
        text: data.moved
          ? `Moved to "${target?.name ?? "the selected space"}".`
          : "Already in that space.",
        kind: "success",
      });
      await load();
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
      const body = moveTargetIri ? { space: moveTargetIri } : {};
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
      if (data.id) {
        await router.push(`/discussions/${data.id}`);
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

  const currentUserIri = `/users/${user.id}`;
  const space = discussion ? findSpace(spaces, spaceIriOf(discussion)) : undefined;
  const isAuthor = !!discussion && currentUserIri === discussion.author["@id"];
  const isSpaceAdmin = !!space?.userMemberships.some(
    (m) => m.user.id === user.id && m.role === "admin",
  );
  const canEdit = isAuthor;
  const canDelete = isAuthor || isSpaceAdmin;
  const canModerate = isSpaceAdmin;
  const canMove = isAuthor || isSpaceAdmin;
  const otherSpaces = discussion
    ? spaces.filter((s) => s["@id"] !== spaceIriOf(discussion))
    : [];

  return (
    <>
      <Head>
        <title>
          {discussion ? `${discussion.title} - Aura` : "Discussion - Aura"}
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-3xl mx-auto px-4 py-8 space-y-4">
          <Button asChild variant="link" size="sm" className="px-0 h-auto">
            <Link href="/discussions" data-testid="discussion-back-link">
              <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Discussions
            </Link>
          </Button>

          {notFound ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  Discussion not found, or you don&apos;t have access.
                </p>
                <Button asChild variant="link" className="px-0">
                  <Link href="/discussions">Back to discussions</Link>
                </Button>
              </CardContent>
            </Card>
          ) : isLoading || !discussion ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <div className="space-y-4" data-testid="discussion-detail">
              {/* Header */}
              <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <CategoryBadge category={discussion.category} />
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
                  {space && (
                    <Link
                      href={`/spaces/${space.id}`}
                      className="text-xs text-muted-foreground hover:underline"
                    >
                      in {space.name}
                    </Link>
                  )}
                </div>

                <h1
                  className="text-2xl font-bold leading-tight"
                  data-testid="discussion-detail-title"
                >
                  {discussion.title}
                </h1>

                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                  <span className="flex items-center gap-2">
                    <UserAvatar user={discussion.author} size="sm" />
                    <span className="font-medium text-foreground">
                      {displayName(discussion.author)}
                    </span>
                  </span>
                  <span>posted {formatRelative(discussion.createdAt)}</span>
                  {discussion.updatedAt && (
                    <span className="italic">· edited {formatRelative(discussion.updatedAt)}</span>
                  )}
                  <span className="flex items-center gap-1">
                    <MessageSquare className="h-3.5 w-3.5" />
                    {comments.length} {comments.length === 1 ? "reply" : "replies"}
                  </span>
                  {discussion.participants.length > 0 && (
                    <AvatarStack
                      users={discussion.participants}
                      total={discussion.participantCount}
                    />
                  )}
                </div>
              </div>

              {/* Body / edit */}
              <Card>
                <CardContent className="pt-6 space-y-4">
                  {!editing ? (
                    <MarkdownView source={discussion.body} className="text-sm" />
                  ) : (
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
                            setEditCategory(e.target.value as DiscussionCategory)
                          }
                          className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                          {(Object.keys(CATEGORY_LABEL) as DiscussionCategory[]).map(
                            (cat) => (
                              <option key={cat} value={cat}>
                                {CATEGORY_LABEL[cat]}
                              </option>
                            ),
                          )}
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
                          disabled={savingEdit || !editTitle.trim() || !editBody.trim()}
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
                </CardContent>
              </Card>

              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              {/* Manage toolbar */}
              {!editing && (canEdit || canDelete || canModerate || canMove) && (
                <div
                  className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-background px-3 py-2"
                  data-testid="discussion-manage"
                >
                  <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Manage
                  </span>
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
                  {canMove && (
                    <>
                      <select
                        id="discussion-move-target"
                        value={moveTargetIri}
                        onChange={(e) => setMoveTargetIri(e.target.value)}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        data-testid="discussion-move-select"
                        aria-label="Move or copy to space"
                      >
                        <option value="">Move/copy to…</option>
                        {otherSpaces.map((s) => (
                          <option key={s["@id"]} value={s["@id"]}>
                            {s.name}
                            {s.isPersonal ? " (Private)" : ""}
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
                            ? "Copy this discussion into the selected space"
                            : "Copy this discussion into the current space"
                        }
                      >
                        {isCopying ? "Copying…" : "Copy"}
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
                      className="ml-auto text-destructive hover:text-destructive"
                      data-testid="discussion-delete"
                    >
                      <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                    </Button>
                  )}
                  {moveMessage && (
                    <span
                      role="alert"
                      className={
                        "w-full text-xs " +
                        (moveMessage.kind === "success"
                          ? "text-muted-foreground"
                          : "text-destructive")
                      }
                    >
                      {moveMessage.text}
                    </span>
                  )}
                </div>
              )}

              {/* Replies */}
              <section className="space-y-3 pt-2">
                <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  <MessageSquare className="h-3.5 w-3.5" />
                  {comments.length} {comments.length === 1 ? "reply" : "replies"}
                </h2>
                <CommentsPanel
                  parentLabel={discussion.title}
                  comments={comments}
                  isLoading={false}
                  currentUserIri={currentUserIri}
                  canModerate={canModerate}
                  canCompose={!discussion.isLocked}
                  composerNotice={
                    <div
                      className="flex items-start gap-3 rounded-lg border border-border bg-muted/50 p-4"
                      data-testid="discussion-locked-notice"
                    >
                      <Lock className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                      <div className="text-sm">
                        <p className="font-medium">This thread is locked</p>
                        <p className="text-muted-foreground">
                          A space admin closed replies on this discussion. You can
                          still read and react, but new comments are disabled
                          {isSpaceAdmin
                            ? " — unlock it from the manage bar above to reopen."
                            : "."}
                        </p>
                      </div>
                    </div>
                  }
                  onCreate={handleCreateComment}
                  onEdit={handleEditComment}
                  onDelete={handleDeleteComment}
                />
              </section>
            </div>
          )}
        </div>
      </main>
    </>
  );
};

export default DiscussionDetailPage;
