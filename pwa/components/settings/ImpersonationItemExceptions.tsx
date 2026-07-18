import { useEffect, useState } from "react";
import { ChevronDown, X } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import {
  useAuth,
  type ImpersonationItemType,
  type ImpersonationLevel,
} from "@/contexts/AuthContext";
import { usePreferencePersist } from "@/lib/usePreferencePersist";
import { cn } from "@/lib/utils";

/**
 * Per-item impersonation overrides. For each addressable type (boards,
 * pages, tasks) the user can pin specific items to none/view/edit,
 * overriding the category default from the PermissionTree above. Stored under
 * the `impersonationItemAccess` preference; enforced server-side by
 * ImpersonationAccessListener (item routes) + ImpersonationItemScope (lists).
 */

const LEVELS: { value: ImpersonationLevel; label: string }[] = [
  { value: "none", label: "None" },
  { value: "view", label: "View" },
  { value: "edit", label: "Edit" },
];

const TYPES: {
  type: "board" | "page" | "task";
  label: string;
  endpoint: string;
}[] = [
  { type: "board", label: "Boards", endpoint: "/boards" },
  { type: "page", label: "Pages", endpoint: "/pages" },
  { type: "task", label: "Tasks", endpoint: "/tasks" },
];

const EMPTY: Record<string, Record<string, ImpersonationLevel>> = {
  board: {},
  page: {},
  task: {},
};

interface Item {
  id: string;
  title: string;
}

const LevelButtons = ({
  value,
  onChange,
}: {
  value: ImpersonationLevel;
  onChange: (level: ImpersonationLevel) => void;
}) => (
  <div className="inline-flex shrink-0 gap-0.5 rounded-md border p-0.5">
    {LEVELS.map((lvl) => (
      <button
        key={lvl.value}
        type="button"
        aria-pressed={value === lvl.value}
        onClick={() => onChange(lvl.value)}
        className={cn(
          "rounded px-2 py-0.5 text-xs font-medium transition-colors",
          value === lvl.value
            ? "bg-primary text-primary-foreground"
            : "text-muted-foreground hover:bg-accent",
        )}
      >
        {lvl.label}
      </button>
    ))}
  </div>
);

const ImpersonationItemExceptions = () => {
  const { user } = useAuth();
  const { persist } = usePreferencePersist();
  const itemAccess: Record<string, Record<string, ImpersonationLevel>> =
    user?.preferences?.impersonationItemAccess ?? {};

  const setOverride = (
    type: string,
    id: string,
    level: ImpersonationLevel | null,
  ) => {
    const full = { ...EMPTY, ...itemAccess };
    const typeMap = { ...(full[type] ?? {}) };
    if (level === null) delete typeMap[id];
    else typeMap[id] = level;
    void persist({
      impersonationItemAccess: { ...full, [type]: typeMap } as Record<
        ImpersonationItemType,
        Record<string, ImpersonationLevel>
      >,
    });
  };

  return (
    <div className="space-y-1">
      <p className="mb-1 text-xs font-medium uppercase tracking-wider text-muted-foreground">
        Per-item exceptions
      </p>
      <p className="pb-1 text-xs text-muted-foreground">
        Pin individual items to override their category default above.
      </p>
      {TYPES.map((t) => (
        <TypeSection
          key={t.type}
          type={t.type}
          label={t.label}
          endpoint={t.endpoint}
          overrides={itemAccess[t.type] ?? {}}
          onSet={(id, level) => setOverride(t.type, id, level)}
        />
      ))}
    </div>
  );
};

const TypeSection = ({
  type,
  label,
  endpoint,
  overrides,
  onSet,
}: {
  type: string;
  label: string;
  endpoint: string;
  overrides: Record<string, ImpersonationLevel>;
  onSet: (id: string, level: ImpersonationLevel | null) => void;
}) => {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<Item[] | null>(null);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open || items !== null || loading) return;
    setLoading(true);
    void (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}${endpoint}?itemsPerPage=100`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        });
        const data = await res.json();
        const list = (data.member ?? data["hydra:member"] ?? []) as {
          id: string;
          title?: string;
        }[];
        setItems(
          list.map((i) => ({ id: i.id, title: i.title || `Item ${i.id.slice(0, 8)}` })),
        );
      } catch {
        setItems([]);
      } finally {
        setLoading(false);
      }
    })();
  }, [open, items, loading, endpoint]);

  const overrideIds = Object.keys(overrides);
  const titleFor = (id: string) =>
    items?.find((i) => i.id === id)?.title ?? `Item ${id.slice(0, 8)}`;
  const addable = (items ?? []).filter((i) => !(i.id in overrides));

  return (
    <div className="rounded-md">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center gap-1.5 py-1.5 text-left text-sm font-medium"
        aria-expanded={open}
      >
        <ChevronDown
          className={cn(
            "h-4 w-4 text-muted-foreground transition-transform",
            open ? "" : "-rotate-90",
          )}
          aria-hidden
        />
        {label}
        {overrideIds.length > 0 ? (
          <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
            {overrideIds.length}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="ml-6 space-y-2 border-l pl-3 pb-2">
          {overrideIds.length === 0 ? (
            <p className="text-xs text-muted-foreground">No exceptions yet.</p>
          ) : (
            overrideIds.map((id) => (
              <div key={id} className="flex items-center justify-between gap-3">
                <span className="truncate text-sm">{titleFor(id)}</span>
                <div className="flex shrink-0 items-center gap-1.5">
                  <LevelButtons
                    value={overrides[id]}
                    onChange={(level) => onSet(id, level)}
                  />
                  <button
                    type="button"
                    aria-label={`Remove exception for ${titleFor(id)}`}
                    onClick={() => onSet(id, null)}
                    className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                  >
                    <X className="h-3.5 w-3.5" />
                  </button>
                </div>
              </div>
            ))
          )}

          {loading ? (
            <p className="text-xs text-muted-foreground">Loading items…</p>
          ) : (
            <select
              value=""
              onChange={(e) => {
                if (e.target.value) onSet(e.target.value, "view");
              }}
              className="w-full rounded-md border bg-background px-2 py-1 text-sm"
              aria-label={`Add ${label} exception`}
            >
              <option value="">Add an exception…</option>
              {addable.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.title}
                </option>
              ))}
            </select>
          )}
        </div>
      ) : null}
    </div>
  );
};

export default ImpersonationItemExceptions;
