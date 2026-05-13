import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, Lock, Pencil, Pin, Trash2 } from "lucide-react";
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
      const res = await fetch(
        `${ENTRYPOINT}/discussions/${encodeURIComponent(did)}`,
        {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        },
      );
      if (res.status === 404 || res.status === 403) {
        setNotFound(true);
        return;
      }
      if (!res.ok) throw new Error("Failed to load discussion.");
      const data: DiscussionDetail = await res.json();
      setDiscussion(data);
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
    if (!res.ok) {
      throw new Error(await errorMessage(res));
    }
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
  const isAuthor =
    !!discussion && currentUserIri === discussion.author["@id"];
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
            <>
              <Button
                asChild
                variant="link"
                size="sm"
                className="px-0 h-auto"
              >
                <Link
                  href="/discussions"
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
                        {space && (
                          <>
                            {" · in "}
                            <Link
                              href={`/spaces/${space.id}`}
                              className="hover:underline"
                            >
                              {space.name}
                            </Link>
                          </>
                        )}
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

                  {!editing && (canEdit || canDelete || canModerate) && (
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
                  )}

                  {canMove && (
                    <div
                      className="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t"
                      data-testid="discussion-move-form"
                    >
                      <Label
                        htmlFor="discussion-move-target"
                        className="text-xs text-muted-foreground"
                      >
                        Move or copy to space
                      </Label>
                      <select
                        id="discussion-move-target"
                        value={moveTargetIri}
                        onChange={(e) => setMoveTargetIri(e.target.value)}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        data-testid="discussion-move-select"
                      >
                        <option value="">Pick a space…</option>
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
                  )}
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
