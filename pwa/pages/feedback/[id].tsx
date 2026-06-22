import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { ArrowLeft, MessageSquare, Pencil, Trash2 } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { ApiError, apiGet, apiGetCollection, apiSend } from "@/lib/apiClient";
import { displayName } from "@/lib/userDisplay";
import {
  STATUS_META,
  STATUS_ORDER,
  TYPE_META,
  TYPE_ORDER,
  formatRelative,
  type Feedback,
  type FeedbackStatus,
  type FeedbackType,
} from "@/lib/feedbackTypes";
import VoteControl from "@/components/feedback/VoteControl";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import UserAvatar from "@/components/user/UserAvatar";
import CommentsPanel, {
  type Comment as CommentRow,
} from "@/components/common/CommentsPanel";
import { useCommentLiveUpdates } from "@/lib/useCommentLiveUpdates";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";

const FeedbackDetailPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const { id } = router.query;
  const fid = typeof id === "string" ? id : null;

  const [ticket, setTicket] = useState<Feedback | null>(null);
  const [comments, setComments] = useState<CommentRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [editing, setEditing] = useState(false);
  const [editTitle, setEditTitle] = useState("");
  const [editType, setEditType] = useState<FeedbackType>("feature");
  const [editBody, setEditBody] = useState("");
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
    if (!fid) return;
    setError(null);
    setIsLoading(true);
    try {
      const data = await apiGet<Feedback>(`/feedback/${encodeURIComponent(fid)}`);
      setTicket(data);
      const rows = await apiGetCollection<CommentRow>(
        `/comments?feedback=${encodeURIComponent(data["@id"])}&itemsPerPage=100`,
      );
      setComments(rows);
    } catch (err) {
      if (err instanceof ApiError && (err.status === 404 || err.status === 403)) {
        setNotFound(true);
        return;
      }
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [fid]);

  useEffect(() => {
    if (isAuthenticated && fid) void load();
  }, [isAuthenticated, fid, load]);

  const patch = useCallback(
    async (body: Partial<Pick<Feedback, "title" | "type" | "status">> & { description?: string }) => {
      if (!ticket) return null;
      const updated = await apiSend<Feedback>("PATCH", ticket["@id"], {
        body,
        contentType: "application/merge-patch+json",
        errorMessage: "Failed to update.",
      });
      if (updated) setTicket(updated);
      return updated;
    },
    [ticket],
  );

  const handleVote = async (value: 1 | -1) => {
    if (!ticket) return;
    try {
      const res = await apiSend<{ score: number; myVote: number }>(
        "POST",
        `/feedback/${ticket.id}/vote`,
        { body: { value }, errorMessage: "Failed to vote." },
      );
      if (res) setTicket({ ...ticket, score: res.score, myVote: res.myVote });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to vote.");
    }
  };

  const startEdit = () => {
    if (!ticket) return;
    setEditTitle(ticket.title);
    setEditType(ticket.type);
    setEditBody(ticket.description);
    setEditorKey((k) => k + 1);
    setEditing(true);
    setEditError(null);
  };

  const saveEdit = async () => {
    const trimTitle = editTitle.trim();
    const trimBody = editBody.trim();
    if (!trimTitle || !trimBody) return;
    setSavingEdit(true);
    setEditError(null);
    try {
      await patch({ title: trimTitle, type: editType, description: editBody });
      setEditing(false);
    } catch (err) {
      setEditError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSavingEdit(false);
    }
  };

  const changeStatus = async (status: FeedbackStatus) => {
    setBusy(true);
    setError(null);
    try {
      await patch({ status });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update status.");
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!ticket) return;
    if (!window.confirm(`Delete "${ticket.title}"? This can't be undone.`)) return;
    setBusy(true);
    setError(null);
    try {
      await apiSend("DELETE", ticket["@id"], { errorMessage: "Failed to delete." });
      void router.replace("/feedback");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
      setBusy(false);
    }
  };

  // --- Comments ---
  const handleCreateComment = async (body: string): Promise<void> => {
    if (!ticket) return;
    const created = await apiSend<CommentRow>("POST", "/comments", {
      body: { feedback: ticket["@id"], body },
      errorMessage: "Failed to post comment.",
    });
    if (created) {
      setComments((prev) =>
        prev.some((c) => c["@id"] === created["@id"]) ? prev : [...prev, created],
      );
    }
  };

  const handleEditComment = async (comment: CommentRow, body: string): Promise<void> => {
    const updated = await apiSend<CommentRow>("PATCH", comment["@id"], {
      body: { body },
      contentType: "application/merge-patch+json",
      errorMessage: "Failed to save comment.",
    });
    if (updated) {
      setComments((prev) => prev.map((c) => (c["@id"] === updated["@id"] ? updated : c)));
    }
  };

  const handleDeleteComment = async (comment: CommentRow): Promise<void> => {
    await apiSend("DELETE", comment["@id"], { errorMessage: "Failed to delete comment." });
    setComments((prev) => prev.filter((c) => c["@id"] !== comment["@id"]));
  };

  useCommentLiveUpdates(ticket?.["@id"] ?? null, !!ticket, (event) => {
    if (event.type === "delete") {
      setComments((prev) => prev.filter((c) => c["@id"] !== event.id));
      return;
    }
    const incoming = event.comment as unknown as CommentRow;
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

  const isAdmin = !!user.roles?.includes("ROLE_ADMIN");
  const currentUserIri = `/users/${user.id}`;
  const isOwner = !!ticket && ticket.owner["@id"] === currentUserIri;
  const canEdit = isOwner || isAdmin;
  const canDelete = isOwner || isAdmin;

  return (
    <>
      <Head>
        <title>
          {ticket ? `${ticket.title} - Madori` : "Feedback - Madori"}
        </title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-3xl mx-auto px-4 py-8 space-y-4">
          <Button asChild variant="link" size="sm" className="px-0 h-auto">
            <Link href="/feedback" data-testid="feedback-back-link">
              <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Feedback
            </Link>
          </Button>

          {notFound ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  Feedback not found, or you don&apos;t have access.
                </p>
                <Button asChild variant="link" className="px-0">
                  <Link href="/feedback">Back to feedback</Link>
                </Button>
              </CardContent>
            </Card>
          ) : isLoading || !ticket ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <div className="space-y-4" data-testid="feedback-detail">
              <div className="flex items-start gap-4">
                <VoteControl
                  score={ticket.score}
                  myVote={ticket.myVote}
                  onVote={(v) => void handleVote(v)}
                />
                <div className="min-w-0 flex-1 space-y-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-muted-foreground tabular-nums">
                      #{ticket.ticketNumber}
                    </span>
                    <Badge className={cn("border-0", TYPE_META[ticket.type].badgeClass)}>
                      {TYPE_META[ticket.type].label}
                    </Badge>
                    <Badge className={cn("border-0", STATUS_META[ticket.status].badgeClass)}>
                      {STATUS_META[ticket.status].label}
                    </Badge>
                  </div>
                  {!editing && (
                    <h1
                      className="text-2xl font-bold leading-tight"
                      data-testid="feedback-detail-title"
                    >
                      {ticket.title}
                    </h1>
                  )}
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                    <span className="flex items-center gap-2">
                      <UserAvatar user={ticket.owner} size="sm" />
                      <span className="font-medium text-foreground">
                        {displayName(ticket.owner)}
                      </span>
                    </span>
                    <span>filed {formatRelative(ticket.createdAt)}</span>
                    {ticket.updatedAt && (
                      <span className="italic">· edited {formatRelative(ticket.updatedAt)}</span>
                    )}
                    <span className="flex items-center gap-1">
                      <MessageSquare className="h-3.5 w-3.5" />
                      {comments.length} {comments.length === 1 ? "comment" : "comments"}
                    </span>
                  </div>
                </div>
              </div>

              {/* Body / edit */}
              <Card>
                <CardContent className="pt-6 space-y-4">
                  {!editing ? (
                    <MarkdownView source={ticket.description} className="text-sm" />
                  ) : (
                    <div className="space-y-3">
                      <div className="space-y-1.5">
                        <Label>Type</Label>
                        <div className="flex gap-2">
                          {TYPE_ORDER.map((t) => (
                            <button
                              key={t}
                              type="button"
                              onClick={() => setEditType(t)}
                              className={cn(
                                "rounded-md border px-3 py-1.5 text-sm font-medium transition-colors",
                                editType === t
                                  ? "border-cyan-700 bg-cyan-700 text-white"
                                  : "border-input bg-background hover:bg-accent",
                              )}
                            >
                              {TYPE_META[t].label}
                            </button>
                          ))}
                        </div>
                      </div>
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
                      <MarkdownEditor
                        key={editorKey}
                        ariaLabel="Edit feedback description"
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
                          data-testid="feedback-save-edit"
                        >
                          {savingEdit ? "Saving…" : "Save"}
                        </Button>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={() => setEditing(false)}
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
              {!editing && (canEdit || canDelete || isAdmin) && (
                <div
                  className="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-background px-3 py-2"
                  data-testid="feedback-manage"
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
                      data-testid="feedback-edit"
                    >
                      <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
                    </Button>
                  )}
                  {isAdmin && (
                    <label className="flex items-center gap-1.5 text-sm">
                      <span className="text-muted-foreground">Status</span>
                      <select
                        value={ticket.status}
                        onChange={(e) => void changeStatus(e.target.value as FeedbackStatus)}
                        disabled={busy}
                        className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                        data-testid="feedback-status-select"
                        aria-label="Change status"
                      >
                        {STATUS_ORDER.map((s) => (
                          <option key={s} value={s}>
                            {STATUS_META[s].label}
                          </option>
                        ))}
                      </select>
                    </label>
                  )}
                  {canDelete && (
                    <Button
                      type="button"
                      size="sm"
                      variant="ghost"
                      onClick={() => void remove()}
                      disabled={busy}
                      className="ml-auto text-destructive hover:text-destructive"
                      data-testid="feedback-delete"
                    >
                      <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                    </Button>
                  )}
                </div>
              )}

              {/* Comments */}
              <section className="space-y-3 pt-2">
                <h2 className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  <MessageSquare className="h-3.5 w-3.5" />
                  {comments.length} {comments.length === 1 ? "comment" : "comments"}
                </h2>
                <CommentsPanel
                  parentLabel={ticket.title}
                  comments={comments}
                  isLoading={false}
                  currentUserIri={currentUserIri}
                  canModerate={isAdmin}
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

export default FeedbackDetailPage;
