import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { trackEvent } from "@/lib/analytics";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { contentSectionFor } from "@/lib/contentSections";
import { cn } from "@/lib/utils";
import MarkdownEditor from "@/components/editor/MarkdownEditor";
import MarkdownView from "@/components/editor/MarkdownView";
import PageHeader from "@/components/common/PageHeader";
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

interface Board {
  "@id": string;
  id: string;
  title: string;
  description: string | null;
  createdOn: string;
  owner: Member;
  members: Member[];
}

const errorMessage = (err: unknown, fallback: string): string =>
  err instanceof Error ? err.message : fallback;

const Boards = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  // The create form is hidden behind a "New board" toggle (mirrors the
  // Pages page) rather than sitting open by default.
  const [showComposer, setShowComposer] = useState(false);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  // Bumped after a successful create so the MarkdownEditor remounts empty.
  const [editorResetKey, setEditorResetKey] = useState(0);

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState("");
  const [editDescription, setEditDescription] = useState("");

  // Most recent mutation failure; query failures fall back to the query's
  // own error in `error` below.
  const [actionError, setActionError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  // Scope to the active space (#187). Falls back to an unfiltered GET while
  // the space list is still loading so the page doesn't flash empty on first
  // paint — the access extension caps the result set to the user's spaces
  // either way. react-query keys on the space so switching spaces refetches
  // (and caches each space's list).
  const spaceIri = activeSpace?.["@id"] ?? null;
  const boardsQuery = useQuery({
    queryKey: ["boards", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Board>(
        spaceIri ? `/boards?space=${encodeURIComponent(spaceIri)}` : "/boards",
        { errorMessage: "Failed to load boards." },
      ),
  });
  const boards = boardsQuery.data ?? [];
  const refreshBoards = () => queryClient.invalidateQueries({ queryKey: ["boards"] });

  const boardsMeta = contentSectionFor("boards");
  const BoardsIcon = boardsMeta.icon;

  const createMutation = useMutation({
    mutationFn: () =>
      apiSend<Board>("POST", "/boards", {
        errorMessage: "Failed to create board.",
        body: {
          title: title.trim(),
          description: description.trim() || null,
          // Pin the new board to the active space (#187) so work created
          // in a shared space doesn't silently land in the personal one.
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      }),
    onSuccess: (created) => {
      setTitle("");
      setDescription("");
      setEditorResetKey((k) => k + 1);
      setShowComposer(false);
      setActionError(null);
      trackEvent("board-create");
      // Refresh the list (and the sidebar, which shares the ["boards"]
      // key prefix), then jump straight into the new board.
      void refreshBoards();
      if (created) void router.push(`/boards/${created.id}`);
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to create board.")),
  });

  const updateMutation = useMutation({
    mutationFn: (board: Board) =>
      apiSend("PATCH", board["@id"], {
        contentType: "application/merge-patch+json",
        errorMessage: "Failed to update board.",
        body: {
          title: editTitle.trim(),
          description: editDescription.trim() || null,
        },
      }),
    onSuccess: () => {
      setEditingId(null);
      setActionError(null);
      void refreshBoards();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to update board.")),
  });

  const deleteMutation = useMutation({
    mutationFn: (board: Board) =>
      apiSend("DELETE", board["@id"], { errorMessage: "Failed to delete board." }),
    onSuccess: () => {
      setActionError(null);
      void refreshBoards();
    },
    onError: (err) => setActionError(errorMessage(err, "Failed to delete board.")),
  });

  const error =
    actionError ??
    (boardsQuery.isError ? errorMessage(boardsQuery.error, "Failed to load boards.") : null);

  const handleCreate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!title.trim()) return;
    createMutation.mutate();
  };

  const startEdit = (board: Board) => {
    setEditingId(board["@id"]);
    setEditTitle(board.title);
    setEditDescription(board.description ?? "");
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const handleUpdate = (event: FormEvent<HTMLFormElement>, board: Board) => {
    event.preventDefault();
    if (!editTitle.trim()) return;
    updateMutation.mutate(board);
  };

  const handleDelete = (board: Board) => {
    // Deleting a board deletes it for every member, and its tasks revert to
    // personal (board_id SET NULL). Make sure the user is aware.
    if (
      !window.confirm(
        `Delete board "${board.title}"? This removes it for all members; board tasks become personal tasks of their owners.`,
      )
    ) {
      return;
    }
    deleteMutation.mutate(board);
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
        <title>Boards - Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-5xl mx-auto">
          <PageHeader
            title="Boards"
            icon={
              <BoardsIcon className={cn("h-6 w-6 shrink-0", boardsMeta.iconClass)} />
            }
            actions={
              can("boards", "create") ? (
                <Button
                  size="sm"
                  onClick={() => setShowComposer((v) => !v)}
                  data-testid="new-board-button"
                >
                  {showComposer ? "Cancel" : "New board"}
                </Button>
              ) : undefined
            }
          />

          {showComposer && (
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
                      autoFocus
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
                  <Button type="submit" disabled={createMutation.isPending || !title.trim()}>
                    {createMutation.isPending ? "Adding..." : "Add Board"}
                  </Button>
                </form>
              </CardContent>
            </Card>
          )}

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {boardsQuery.isLoading ? (
            <p className="text-muted-foreground">Loading boards...</p>
          ) : boards.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground">
                  No boards yet. Click &ldquo;New board&rdquo; to start collaborating.
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-2" data-testid="board-list">
              {boards.map((board) => (
                <li key={board["@id"]} data-testid="board-item">
                  <Card>
                    <CardContent className="pt-4 pb-4">
                      {editingId === board["@id"] ? (
                        <form onSubmit={(e) => handleUpdate(e, board)} className="space-y-3">
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
                              onClick={cancelEdit}
                            >
                              Cancel
                            </Button>
                          </div>
                        </form>
                      ) : (
                        <div>
                          <div className="flex items-start justify-between gap-3">
                            <h2 className="font-semibold">
                              <Link
                                href={`/boards/${board.id}`}
                                className="text-primary hover:underline no-underline"
                              >
                                {board.title}
                              </Link>
                            </h2>
                            <div className="flex items-center gap-1 shrink-0">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => startEdit(board)}
                              >
                                Edit
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleDelete(board)}
                                aria-label={`Delete "${board.title}"`}
                                className="text-destructive hover:text-destructive"
                              >
                                Delete
                              </Button>
                            </div>
                          </div>
                          {board.description && (
                            <MarkdownView source={board.description} className="mt-1" />
                          )}
                          {board.members.length > 0 && (
                            <div
                              className="mt-2 flex flex-wrap items-center gap-1"
                              data-testid="board-members"
                            >
                              <span className="text-xs text-muted-foreground">Members:</span>
                              {board.members.map((member) => (
                                <Badge
                                  key={member["@id"]}
                                  variant="secondary"
                                  data-testid="board-member"
                                >
                                  {member.email}
                                </Badge>
                              ))}
                            </div>
                          )}
                        </div>
                      )}
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default Boards;
