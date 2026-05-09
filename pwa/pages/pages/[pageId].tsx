import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { ArrowLeft, Pencil, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

interface SpaceRef {
  "@id": string;
  id: string;
  name: string;
}

interface PageDetail {
  "@id": string;
  id: string;
  title: string;
  body: string;
  position: number;
  parent: string | null;
  createdAt: string;
  updatedAt: string | null;
  space: SpaceRef;
  createdBy: AvatarUser & { "@id": string; id: string };
}

interface PageComment {
  "@id": string;
  id: string;
  body: string;
  createdAt: string;
  updatedAt: string | null;
  author: AvatarUser & { "@id": string; id: string };
}

interface ChildPage {
  "@id": string;
  id: string;
  title: string;
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    "Request failed."
  );
};

const PageDetailView = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { pageId } = router.query;
  const pid = typeof pageId === "string" ? pageId : null;

  const [page, setPage] = useState<PageDetail | null>(null);
  const [children, setChildren] = useState<ChildPage[]>([]);
  const [comments, setComments] = useState<PageComment[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Edit state
  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editBody, setEditBody] = useState("");
  const [editorKey, setEditorKey] = useState(0);
  const [savingEdit, setSavingEdit] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Comment composer
  const [newComment, setNewComment] = useState("");
  const [postingComment, setPostingComment] = useState(false);
  const [commentError, setCommentError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Refetches just the comment thread without flipping `isLoading`.
  // Posting a comment used to call `load()` which blanked the whole
  // detail card to "Loading…" mid-flight; on slow CI runners that
  // race outlived Playwright's 5s default and the new comment never
  // made it into view before the assertion fired.
  const refreshComments = useCallback(async (pageIri: string) => {
    const res = await fetch(
      `${ENTRYPOINT}/page_comments?page=${encodeURIComponent(pageIri)}&itemsPerPage=100`,
      { credentials: "include", headers: { Accept: "application/ld+json" } },
    );
    if (!res.ok) return;
    const collection: Collection<PageComment> = await res.json();
    setComments(collection.member ?? collection["hydra:member"] ?? []);
  }, []);

  const load = useCallback(async () => {
    if (!pid) return;
    setError(null);
    setIsLoading(true);
    try {
      const pageRes = await fetch(`${ENTRYPOINT}/pages/${encodeURIComponent(pid)}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (pageRes.status === 404 || pageRes.status === 403) {
        setNotFound(true);
        return;
      }
      if (!pageRes.ok) throw new Error("Failed to load page.");
      const data: PageDetail = await pageRes.json();
      setPage(data);

      const [childrenRes, commentsRes] = await Promise.all([
        fetch(
          `${ENTRYPOINT}/pages?parent=${encodeURIComponent(data["@id"])}&itemsPerPage=100`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
        fetch(
          `${ENTRYPOINT}/page_comments?page=${encodeURIComponent(data["@id"])}&itemsPerPage=100`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
      ]);
      if (childrenRes.ok) {
        const collection: Collection<ChildPage> = await childrenRes.json();
        setChildren(collection.member ?? collection["hydra:member"] ?? []);
      }
      if (commentsRes.ok) {
        const collection: Collection<PageComment> = await commentsRes.json();
        setComments(collection.member ?? collection["hydra:member"] ?? []);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [pid]);

  useEffect(() => {
    if (isAuthenticated && pid) void load();
  }, [isAuthenticated, pid, load]);

  const isAuthor = !!page && !!user && page.createdBy?.id === user.id;
  const isSpaceAdmin =
    !!page &&
    !!user &&
    spaces
      .find((s) => s["@id"] === page.space["@id"])
      ?.userMemberships.some(
        (m) => m.user.id === user.id && m.role === "admin",
      ) === true;
  const canEdit = isAuthor || isSpaceAdmin;

  const startEdit = () => {
    if (!page) return;
    setEditTitle(page.title);
    setEditBody(page.body);
    setEditorKey((k) => k + 1);
    setEditError(null);
    setEditing(true);
  };

  const cancelEdit = () => {
    setEditing(false);
    setEditError(null);
  };

  const handleSaveEdit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!page) return;
    setSavingEdit(true);
    setEditError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${page["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ title: editTitle.trim(), body: editBody }),
      });
      if (!res.ok) {
        throw new Error(await errorMessage(res));
      }
      setEditing(false);
      await load();
    } catch (err) {
      setEditError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSavingEdit(false);
    }
  };

  const handleDelete = async () => {
    if (!page) return;
    if (
      !window.confirm(
        `Delete "${page.title}"? Sub-pages and comments go with it. This can't be undone.`,
      )
    ) {
      return;
    }
    setBusy(true);
    try {
      const res = await fetch(`${ENTRYPOINT}${page["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error(await errorMessage(res));
      }
      await router.push("/pages");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
    } finally {
      setBusy(false);
    }
  };

  const handlePostComment = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!page || !newComment.trim()) return;
    setPostingComment(true);
    setCommentError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/page_comments`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          page: page["@id"],
          body: newComment.trim(),
        }),
      });
      if (!res.ok) {
        throw new Error(await errorMessage(res));
      }
      setNewComment("");
      await refreshComments(page["@id"]);
    } catch (err) {
      setCommentError(err instanceof Error ? err.message : "Failed to post.");
    } finally {
      setPostingComment(false);
    }
  };

  const handleDeleteComment = async (comment: PageComment) => {
    if (!window.confirm("Delete this comment?")) return;
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      setError(await errorMessage(res));
      return;
    }
    setComments((prev) => prev.filter((c) => c["@id"] !== comment["@id"]));
  };

  if (authLoading || !user) {
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
            <h1 className="text-xl font-bold mb-2">Page not found</h1>
            <p className="text-muted-foreground mb-4">
              It may have been deleted, or you may not have access.
            </p>
            <Link href="/pages" className="text-primary font-medium">
              Back to pages
            </Link>
          </CardContent>
        </Card>
      </main>
    );
  }

  return (
    <>
      <Head>
        <title>{page ? `${page.title} - Aura` : "Page - Aura"}</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-3xl mx-auto px-4 py-8 space-y-6">
          <Button asChild variant="link" size="sm" className="px-0 h-auto">
            <Link href="/pages" data-testid="page-back-link">
              <ArrowLeft className="h-3.5 w-3.5 mr-1" />
              All pages
            </Link>
          </Button>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading || !page ? (
            <p className="text-muted-foreground text-sm">Loading…</p>
          ) : editing ? (
            <Card>
              <CardContent className="pt-6">
                <form onSubmit={handleSaveEdit} className="space-y-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="page-edit-title">Title</Label>
                    <Input
                      id="page-edit-title"
                      type="text"
                      value={editTitle}
                      onChange={(e) => setEditTitle(e.target.value)}
                      maxLength={200}
                      required
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label htmlFor="page-edit-body">Body</Label>
                    <MarkdownEditor
                      key={editorKey}
                      value={editBody}
                      onChange={setEditBody}
                    />
                  </div>
                  {editError && (
                    <Alert variant="destructive">
                      <AlertDescription>{editError}</AlertDescription>
                    </Alert>
                  )}
                  <div className="flex gap-2">
                    <Button
                      type="submit"
                      size="sm"
                      disabled={savingEdit || !editTitle.trim()}
                    >
                      {savingEdit ? "Saving…" : "Save"}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      onClick={cancelEdit}
                    >
                      Cancel
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          ) : (
            <>
              <Card>
                <CardContent className="pt-6">
                  <div className="flex items-start justify-between gap-2 mb-3">
                    <h1 className="text-2xl font-bold" data-testid="page-detail-title">
                      {page.title}
                    </h1>
                    {canEdit && (
                      <div className="flex gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={startEdit}
                          aria-label="Edit page"
                        >
                          <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={handleDelete}
                          disabled={busy}
                          aria-label="Delete page"
                        >
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    )}
                  </div>
                  <div className="flex items-center gap-2 mb-4 text-xs text-muted-foreground">
                    <UserAvatar user={page.createdBy} size="sm" />
                    <span>
                      {displayName(page.createdBy)} ·{" "}
                      {formatRelative(page.createdAt)}
                      {page.updatedAt && " · edited"}
                    </span>
                  </div>
                  {page.body.trim() ? (
                    <MarkdownView source={page.body} />
                  ) : (
                    <p className="text-sm text-muted-foreground italic">
                      No content yet — click the pencil icon to add a body.
                    </p>
                  )}
                </CardContent>
              </Card>

              {children.length > 0 && (
                <Card>
                  <CardContent className="pt-6">
                    <h2 className="text-lg font-semibold mb-3">Sub-pages</h2>
                    <ul className="space-y-1">
                      {children.map((c) => (
                        <li key={c["@id"]}>
                          <Link
                            href={`/pages/${c.id}`}
                            className="text-primary hover:underline"
                          >
                            {c.title}
                          </Link>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              )}

              <Card>
                <CardContent className="pt-6">
                  <h2 className="text-lg font-semibold mb-3">Comments</h2>
                  {comments.length === 0 ? (
                    <p className="text-sm text-muted-foreground mb-4">
                      No comments yet.
                    </p>
                  ) : (
                    <ul className="space-y-3 mb-4" data-testid="page-comments">
                      {comments.map((c) => {
                        const isOwn = user.id === c.author.id;
                        const canDelete = isOwn || isSpaceAdmin;
                        return (
                          <li
                            key={c["@id"]}
                            className="flex items-start gap-3"
                            data-testid="page-comment"
                          >
                            <UserAvatar user={c.author} size="sm" />
                            <div className="min-w-0 flex-1">
                              <p className="text-xs text-muted-foreground">
                                {displayName(c.author)} ·{" "}
                                {formatRelative(c.createdAt)}
                                {c.updatedAt && " · edited"}
                              </p>
                              <p className="text-sm whitespace-pre-wrap break-words">
                                {c.body}
                              </p>
                            </div>
                            {canDelete && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleDeleteComment(c)}
                                aria-label="Delete comment"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            )}
                          </li>
                        );
                      })}
                    </ul>
                  )}

                  <form onSubmit={handlePostComment} className="space-y-2">
                    <Label htmlFor="new-page-comment" className="sr-only">
                      Add a comment
                    </Label>
                    <Textarea
                      id="new-page-comment"
                      value={newComment}
                      onChange={(e) => setNewComment(e.target.value)}
                      placeholder="Add a comment…"
                      rows={3}
                      maxLength={50000}
                    />
                    {commentError && (
                      <Alert variant="destructive">
                        <AlertDescription>{commentError}</AlertDescription>
                      </Alert>
                    )}
                    <Button
                      type="submit"
                      size="sm"
                      disabled={postingComment || !newComment.trim()}
                    >
                      {postingComment ? "Posting…" : "Post comment"}
                    </Button>
                  </form>
                </CardContent>
              </Card>
            </>
          )}
        </div>
      </main>
    </>
  );
};

export default PageDetailView;
