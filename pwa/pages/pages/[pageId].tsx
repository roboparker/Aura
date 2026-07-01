import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { Pencil, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import { formatRelative } from "@/lib/relativeTime";
import { apiErrorMessage, readCollection } from "@/lib/apiClient";
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

// Unified comment shape + UI (#228) — same as the task surface.
import CommentsPanel, {
  type Comment as PageCommentRow,
} from "@/components/common/CommentsPanel";
import { useCommentLiveUpdates } from "@/lib/useCommentLiveUpdates";

interface ChildPage {
  "@id": string;
  id: string;
  title: string;
}

const PageDetailView = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces } = useActiveSpace();
  const router = useRouter();
  const { pageId } = router.query;
  const pid = typeof pageId === "string" ? pageId : null;

  const [page, setPage] = useState<PageDetail | null>(null);
  const [children, setChildren] = useState<ChildPage[]>([]);
  const [comments, setComments] = useState<PageCommentRow[]>([]);
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
          `${ENTRYPOINT}/comments?page=${encodeURIComponent(data["@id"])}&itemsPerPage=100`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
      ]);
      if (childrenRes.ok) {
        setChildren(readCollection<ChildPage>(await childrenRes.json()));
      }
      if (commentsRes.ok) {
        setComments(readCollection<PageCommentRow>(await commentsRes.json()));
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
        throw new Error(await apiErrorMessage(res, "Request failed."));
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
        throw new Error(await apiErrorMessage(res, "Request failed."));
      }
      await router.push("/pages");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
    } finally {
      setBusy(false);
    }
  };

  const handleCreateComment = async (body: string): Promise<void> => {
    if (!page) return;
    const res = await fetch(`${ENTRYPOINT}/comments`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/ld+json" },
      body: JSON.stringify({ page: page["@id"], body }),
    });
    if (!res.ok) throw new Error(await apiErrorMessage(res, "Request failed."));
    const created: PageCommentRow = await res.json();
    // Optimistic insert; the Mercure echo of this POST may also
    // arrive and tries to add the same IRI — dedupe defensively.
    setComments((prev) =>
      prev.some((c) => c["@id"] === created["@id"])
        ? prev
        : [...prev, created],
    );
  };

  const handleEditComment = async (
    comment: PageCommentRow,
    body: string,
  ): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ body }),
    });
    if (!res.ok) throw new Error(await apiErrorMessage(res, "Request failed."));
    const updated: PageCommentRow = await res.json();
    setComments((prev) =>
      prev.map((c) => (c["@id"] === updated["@id"] ? updated : c)),
    );
  };

  const handleDeleteComment = async (
    comment: PageCommentRow,
  ): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${comment["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) {
      throw new Error(await apiErrorMessage(res, "Request failed."));
    }
    setComments((prev) => prev.filter((c) => c["@id"] !== comment["@id"]));
  };

  // Live updates over Mercure — fold create/update/delete events
  // into local state so a co-editor's posts appear without reload.
  useCommentLiveUpdates(page?.["@id"] ?? null, !!page, (event) => {
    if (event.type === "delete") {
      setComments((prev) => prev.filter((c) => c["@id"] !== event.id));
      return;
    }
    const incoming = event.comment as unknown as PageCommentRow;
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

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <main className="min-h-screen bg-background px-4 py-12">
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
        <title>{page ? `${page.title} - Madori` : "Page - Madori"}</title>
      </Head>
      <main className="min-h-screen bg-background">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-6">
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
                  <CommentsPanel
                    parentLabel={page.title}
                    comments={comments}
                    isLoading={false}
                    currentUserIri={user ? `/users/${user.id}` : null}
                    canModerate={!!isSpaceAdmin}
                    onCreate={handleCreateComment}
                    onEdit={handleEditComment}
                    onDelete={handleDeleteComment}
                  />
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
