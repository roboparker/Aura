import { useCallback, useEffect, useState } from "react";
import { CornerDownRight, ListTree, Plus, X } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

/**
 * Subtasks section for the task drawer. A subtask is a task linked to this one
 * by a `parent` TaskRelationship (this task is the source). Reads the ordered
 * children + completion rollup from `GET /tasks/{id}/subtasks`, lets you toggle
 * each child complete (PATCH the child), unlink it (DELETE the relationship —
 * the child task survives), and add a new one inline (create a task on the same
 * board, then link it).
 *
 * Completion never cascades: a parent isn't auto-completed or blocked by its
 * children — the progress count is informational.
 */
interface SubtaskRow {
  relationshipId: string;
  id: string;
  "@id": string;
  title: string;
  completed: boolean;
  completedOn: string | null;
  dueDate: string | null;
}

interface SubtasksResponse {
  subtasks: SubtaskRow[];
  total: number;
  completed: number;
  parent: { id: string; "@id": string; title: string; completed: boolean } | null;
}

interface SubtasksPanelProps {
  taskIri: string;
  /** Board IRI a new subtask should be created on, mirroring its parent. */
  boardIri: string | null;
  /** Bump to force a reload — e.g. after the parent's own completion toggles. */
  refreshKey?: number;
  /** Notified after any change so the drawer can refresh dependent views. */
  onChanged?: () => void;
}

const SubtasksPanel = ({ taskIri, boardIri, refreshKey, onChanged }: SubtasksPanelProps) => {
  const [data, setData] = useState<SubtasksResponse | null>(null);
  const [adding, setAdding] = useState(false);
  const [title, setTitle] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}${taskIri}/subtasks`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;
      setData(await res.json());
    } catch {
      /* keep prior state on a transient error */
    }
  }, [taskIri]);

  useEffect(() => {
    void load();
  }, [load, refreshKey]);

  const toggleComplete = async (row: SubtaskRow, next: boolean) => {
    // Optimistic: flip the row and the count immediately.
    setData((prev) =>
      prev
        ? {
            ...prev,
            completed: prev.completed + (next ? 1 : -1),
            subtasks: prev.subtasks.map((s) =>
              s.id === row.id ? { ...s, completed: next } : s,
            ),
          }
        : prev,
    );
    try {
      await fetch(`${ENTRYPOINT}${row["@id"]}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({
          completedOn: next ? new Date().toISOString() : null,
        }),
      });
      onChanged?.();
    } catch {
      void load();
    }
  };

  const unlink = async (row: SubtaskRow) => {
    setData((prev) =>
      prev
        ? {
            ...prev,
            total: prev.total - 1,
            completed: prev.completed - (row.completed ? 1 : 0),
            subtasks: prev.subtasks.filter((s) => s.id !== row.id),
          }
        : prev,
    );
    try {
      await fetch(`${ENTRYPOINT}/task_relationships/${row.relationshipId}`, {
        method: "DELETE",
        credentials: "include",
      });
      onChanged?.();
    } catch {
      void load();
    }
  };

  const addSubtask = async () => {
    const trimmed = title.trim();
    if (trimmed.length === 0) return;
    setBusy(true);
    setError(null);
    try {
      // 1) Create the task on the same board as its parent.
      const createRes = await fetch(`${ENTRYPOINT}/tasks`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          title: trimmed,
          ...(boardIri ? { board: boardIri } : {}),
        }),
      });
      if (!createRes.ok) throw new Error("Couldn't create the subtask.");
      const created = await createRes.json();

      // 2) Link it as a child. A freshly-created task has no parent and no
      // descendants, so the single-parent and cycle guards can't reject it.
      const linkRes = await fetch(`${ENTRYPOINT}/task_relationships`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          source: taskIri,
          target: created["@id"],
          type: "parent",
        }),
      });
      if (!linkRes.ok) throw new Error("Created the task but couldn't link it.");

      setTitle("");
      setAdding(false);
      await load();
      onChanged?.();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't add the subtask.");
    } finally {
      setBusy(false);
    }
  };

  const total = data?.total ?? 0;
  const completed = data?.completed ?? 0;
  const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

  return (
    <div className="space-y-2" data-testid="task-subtasks">
      <div className="flex items-center justify-between gap-2">
        <p className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
          <ListTree className="h-3.5 w-3.5" aria-hidden />
          Subtasks
          {total > 0 && (
            <span className="tabular-nums text-muted-foreground" data-testid="subtask-progress">
              {completed}/{total}
            </span>
          )}
        </p>
      </div>

      {total > 0 && (
        <div
          className="h-1.5 overflow-hidden rounded-full bg-muted"
          role="progressbar"
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label="Subtask completion"
        >
          <div
            className="h-full rounded-full bg-emerald-500 transition-[width]"
            style={{ width: `${pct}%` }}
          />
        </div>
      )}

      {data && data.subtasks.length > 0 && (
        <ul className="space-y-1">
          {data.subtasks.map((row) => (
            <li
              key={row.id}
              className="flex items-center gap-2 rounded-md border border-input px-2.5 py-1.5 text-sm"
              data-testid="subtask-item"
            >
              <Checkbox
                checked={row.completed}
                onCheckedChange={(checked) => void toggleComplete(row, checked === true)}
                aria-label={row.completed ? "Mark incomplete" : "Mark complete"}
                data-testid="subtask-toggle"
              />
              <span
                className={cn(
                  "min-w-0 flex-1 truncate",
                  row.completed && "text-muted-foreground line-through",
                )}
              >
                {row.title}
              </span>
              <button
                type="button"
                onClick={() => void unlink(row)}
                className="text-muted-foreground hover:text-destructive"
                aria-label="Remove subtask link"
                data-testid="subtask-unlink"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <div className="space-y-2" data-testid="subtask-form">
          <div className="flex items-center gap-1.5">
            <CornerDownRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
            <Input
              autoFocus
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  void addSubtask();
                }
                if (e.key === "Escape") {
                  setAdding(false);
                  setTitle("");
                  setError(null);
                }
              }}
              placeholder="Subtask title…"
              className="h-8"
              data-testid="subtask-input"
            />
          </div>
          {error && <p className="text-xs text-destructive">{error}</p>}
          <div className="flex justify-end gap-1.5">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => {
                setAdding(false);
                setTitle("");
                setError(null);
              }}
            >
              Cancel
            </Button>
            <Button
              type="button"
              size="sm"
              disabled={busy || title.trim().length === 0}
              onClick={() => void addSubtask()}
              data-testid="subtask-save"
            >
              Add
            </Button>
          </div>
        </div>
      ) : (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setAdding(true)}
          className="gap-1.5"
          data-testid="subtask-add"
        >
          <Plus className="h-3.5 w-3.5" /> Add subtask
        </Button>
      )}
    </div>
  );
};

export default SubtasksPanel;
