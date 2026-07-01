import { useCallback, useEffect, useRef, useState } from "react";
import { Link2, Plus, X } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

/**
 * Relationships section for the task drawer. Lists every relationship touching
 * the task (via `GET /tasks/{id}/relationships`) with a direction-aware label,
 * and an add form: pick a directional type + search for the other task, then
 * POST /task_relationships. The directional option resolves to (type, which
 * side is source) so one stored row covers both viewing directions.
 */
interface RelationshipRow {
  "@id": string;
  id: string;
  type: string;
  direction: "outgoing" | "incoming";
  label: string;
  task: {
    "@id": string | null;
    id: string;
    title: string;
    completedOn: string | null;
  };
}

interface TaskHit {
  "@id": string;
  id: string;
  title: string;
}

/** Directional options the user picks from, mapped to the stored shape. */
const REL_OPTIONS = [
  { value: "parent-out", label: "Parent of", type: "parent", currentIsSource: true },
  { value: "parent-in", label: "Child of", type: "parent", currentIsSource: false },
  { value: "required-out", label: "Required for", type: "required", currentIsSource: true },
  { value: "required-in", label: "Required by", type: "required", currentIsSource: false },
  { value: "related", label: "Related to", type: "related", currentIsSource: true },
  { value: "duplicate-out", label: "Duplicate of", type: "duplicate", currentIsSource: true },
  { value: "duplicate-in", label: "Duplicated by", type: "duplicate", currentIsSource: false },
] as const;

interface TaskRelationshipsPanelProps {
  taskIri: string;
}

const TaskRelationshipsPanel = ({ taskIri }: TaskRelationshipsPanelProps) => {
  const [rows, setRows] = useState<RelationshipRow[]>([]);
  const [adding, setAdding] = useState(false);
  const [optionValue, setOptionValue] = useState<string>(REL_OPTIONS[0].value);
  const [query, setQuery] = useState("");
  const [hits, setHits] = useState<TaskHit[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const searchSeq = useRef(0);

  const load = useCallback(async () => {
    try {
      const res = await fetch(`${ENTRYPOINT}${taskIri}/relationships`, {
        credentials: "include",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;
      const data = await res.json();
      setRows(Array.isArray(data.relationships) ? data.relationships : []);
    } catch {
      /* leave the list as-is on a transient error */
    }
  }, [taskIri]);

  useEffect(() => {
    void load();
  }, [load]);

  // Debounced task search for the picker (excludes the current task).
  useEffect(() => {
    if (!adding || query.trim().length === 0) {
      setHits([]);
      return;
    }
    const seq = ++searchSeq.current;
    const handle = setTimeout(() => {
      void fetch(
        `${ENTRYPOINT}/tasks?search=${encodeURIComponent(query.trim())}&itemsPerPage=8`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      )
        .then((res) => (res.ok ? res.json() : { member: [] }))
        .then((data) => {
          if (seq !== searchSeq.current) return;
          const members: TaskHit[] = data.member ?? data["hydra:member"] ?? [];
          setHits(members.filter((t) => t["@id"] !== taskIri));
        })
        .catch(() => {});
    }, 250);
    return () => clearTimeout(handle);
  }, [adding, query, taskIri]);

  const resetAdd = () => {
    setAdding(false);
    setQuery("");
    setHits([]);
    setError(null);
  };

  const addRelationship = async (other: TaskHit) => {
    const option = REL_OPTIONS.find((o) => o.value === optionValue);
    if (!option) return;
    setBusy(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/task_relationships`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          source: option.currentIsSource ? taskIri : other["@id"],
          target: option.currentIsSource ? other["@id"] : taskIri,
          type: option.type,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Couldn't add the relationship.",
        );
      }
      resetAdd();
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Couldn't add the relationship.");
    } finally {
      setBusy(false);
    }
  };

  const removeRelationship = async (row: RelationshipRow) => {
    setRows((prev) => prev.filter((r) => r["@id"] !== row["@id"]));
    try {
      await fetch(`${ENTRYPOINT}${row["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
    } catch {
      void load();
    }
  };

  return (
    <div className="space-y-2" data-testid="task-relationships">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        Relationships
      </p>

      {rows.length > 0 && (
        <ul className="space-y-1.5">
          {rows.map((row) => (
            <li
              key={row["@id"]}
              className="flex items-center gap-2 rounded-md border border-input px-2.5 py-1.5 text-sm"
              data-testid="relationship-item"
            >
              <Link2 className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
              <span className="shrink-0 text-xs text-muted-foreground">{row.label}</span>
              <span
                className={cn(
                  "min-w-0 flex-1 truncate",
                  row.task.completedOn && "text-muted-foreground line-through",
                )}
              >
                {row.task.title}
              </span>
              <button
                type="button"
                onClick={() => void removeRelationship(row)}
                className="text-muted-foreground hover:text-destructive"
                aria-label="Remove relationship"
                data-testid="relationship-remove"
              >
                <X className="h-3.5 w-3.5" />
              </button>
            </li>
          ))}
        </ul>
      )}

      {adding ? (
        <div className="space-y-2 rounded-md border border-input p-2.5" data-testid="relationship-form">
          <select
            value={optionValue}
            onChange={(e) => setOptionValue(e.target.value)}
            className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
            data-testid="relationship-type"
          >
            {REL_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
          <Input
            autoFocus
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search a task…"
            className="h-8"
            data-testid="relationship-search"
          />
          {hits.length > 0 && (
            <ul className="max-h-44 overflow-y-auto rounded-md border">
              {hits.map((hit) => (
                <li key={hit["@id"]}>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => void addRelationship(hit)}
                    className="block w-full truncate px-2.5 py-1.5 text-left text-sm hover:bg-accent disabled:opacity-60"
                    data-testid="relationship-hit"
                  >
                    {hit.title}
                  </button>
                </li>
              ))}
            </ul>
          )}
          {error && <p className="text-xs text-destructive">{error}</p>}
          <div className="flex justify-end">
            <Button type="button" variant="ghost" size="sm" onClick={resetAdd}>
              Cancel
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
          data-testid="relationship-add"
        >
          <Plus className="h-3.5 w-3.5" /> Add relationship
        </Button>
      )}
    </div>
  );
};

export default TaskRelationshipsPanel;
