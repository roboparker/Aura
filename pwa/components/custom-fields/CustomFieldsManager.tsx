import { useCallback, useEffect, useMemo, useState } from "react";
import {
  DndContext,
  DragEndEvent,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { CircleCheck, Copy, GripVertical, History, Plus, Settings } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import CustomFieldSheet from "./CustomFieldSheet";
import CustomFieldChangeLog from "./CustomFieldChangeLog";
import { fieldHandle } from "./handle";
import { KIND_BADGE, kindLabelFor, subtypeLabelFor } from "./kind-editors";
import type {
  CustomFieldDefinition,
  FieldStatsResponse,
} from "./types";

/**
 * Schema editor for a project's custom field catalogue. Renders the
 * fields as a sortable table (drag to reorder → column order on the task
 * list), opens a right-side {@link CustomFieldSheet} for create/edit, and
 * exposes the project-scoped change log. Talks to
 * `/custom_field_definitions` plus the reorder / stats / activity helper
 * endpoints.
 */
interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  projectIri: string;
  /** Project title — slugified for the header `slug · N fields` badge. */
  projectTitle: string;
  /** Active space name, surfaced in the admin notice + reference scope note. */
  spaceName?: string;
  isSpaceAdmin: boolean;
}

const projectIdFromIri = (iri: string): string => iri.split("/").pop() ?? "";

/** `Spring Collection Launch` → `spring-collection-launch` (header badge). */
const slugify = (value: string): string =>
  value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    data.error ||
    "Request failed."
  );
};

const sortByPosition = (
  list: CustomFieldDefinition[],
): CustomFieldDefinition[] =>
  [...list].sort(
    (a, b) => a.position - b.position || a.name.localeCompare(b.name),
  );

const CustomFieldsManager = ({
  projectIri,
  projectTitle,
  spaceName,
  isSpaceAdmin,
}: Props) => {
  const projectId = projectIdFromIri(projectIri);
  const [defs, setDefs] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [total, setTotal] = useState(0);
  const [filled, setFilled] = useState<Record<string, number>>({});
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editing, setEditing] = useState<CustomFieldDefinition | null>(null);
  const [changeLogOpen, setChangeLogOpen] = useState(false);
  const [noticeDismissed, setNoticeDismissed] = useState(false);

  const sensors = useSensors(useSensor(PointerSensor));

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const url = `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(projectIri)}`;
      const res = await fetch(url, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load custom fields.");
      const data: Collection<CustomFieldDefinition> = await res.json();
      setDefs(sortByPosition(data.member ?? data["hydra:member"] ?? []));
    } catch (err) {
      setLoadError(
        err instanceof Error ? err.message : "Failed to load custom fields.",
      );
    } finally {
      setIsLoading(false);
    }
  }, [projectIri]);

  const loadStats = useCallback(async () => {
    try {
      const res = await fetch(
        `${ENTRYPOINT}/projects/${projectId}/custom_field_stats`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok) return;
      const data: FieldStatsResponse = await res.json();
      setTotal(data.total);
      const map: Record<string, number> = {};
      for (const s of data.stats) map[s.definition] = s.filled;
      setFilled(map);
    } catch {
      /* fill counts are a nicety — ignore failures */
    }
  }, [projectId]);

  useEffect(() => {
    void load();
    void loadStats();
  }, [load, loadStats]);

  const nextPosition =
    defs.length > 0 ? Math.max(...defs.map((d) => d.position)) + 1 : 0;

  const slug = slugify(projectTitle) || "project";

  const openCreate = () => {
    setEditing(null);
    setSheetOpen(true);
  };
  const openEdit = (def: CustomFieldDefinition) => {
    setEditing(def);
    setSheetOpen(true);
  };

  const handleSaved = (saved: CustomFieldDefinition) => {
    setDefs((prev) => {
      const exists = prev.some((d) => d["@id"] === saved["@id"]);
      const next = exists
        ? prev.map((d) => (d["@id"] === saved["@id"] ? saved : d))
        : [...prev, saved];
      return sortByPosition(next);
    });
    void loadStats();
  };

  const handleDeleted = (def: CustomFieldDefinition) => {
    setDefs((prev) => prev.filter((d) => d["@id"] !== def["@id"]));
  };

  // Client-side duplicate — there's no copy endpoint, so we POST a fresh
  // definition mirroring the source's kind/subtype/config/footer/nullable
  // with a " copy" name suffix at the end of the column order.
  const handleDuplicate = useCallback(
    async (def: CustomFieldDefinition) => {
      setLoadError(null);
      try {
        const res = await fetch(`${ENTRYPOINT}/custom_field_definitions`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            name: `${def.name} copy`,
            kind: def.kind,
            subtype: def.subtype,
            config: def.config,
            nullable: def.nullable,
            footer: def.footer,
            project: projectIri,
            position: nextPosition,
          }),
        });
        if (!res.ok) throw new Error(await errorMessage(res));
        const saved: CustomFieldDefinition = await res.json();
        handleSaved(saved);
      } catch (err) {
        setLoadError(
          err instanceof Error ? err.message : "Failed to duplicate field.",
        );
      }
    },
    [projectIri, nextPosition],
  );

  const persistOrder = useCallback(
    async (ordered: CustomFieldDefinition[]) => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}/projects/${projectId}/custom_field_definitions/reorder`,
          {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ order: ordered.map((d) => d["@id"]) }),
          },
        );
        if (!res.ok) throw new Error(await errorMessage(res));
      } catch (err) {
        setLoadError(
          err instanceof Error ? err.message : "Failed to save order.",
        );
        void load(); // re-sync to the server's truth
      }
    },
    [projectId, load],
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const from = defs.findIndex((d) => d["@id"] === active.id);
    const to = defs.findIndex((d) => d["@id"] === over.id);
    if (from < 0 || to < 0) return;
    const next = [...defs];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    const renumbered = next.map((d, i) => ({ ...d, position: i }));
    setDefs(renumbered);
    void persistOrder(renumbered);
  };

  const editingValueCount = useMemo(
    () => (editing ? filled[editing["@id"]] : undefined),
    [editing, filled],
  );

  return (
    <div className="space-y-6" data-testid="custom-fields-manager">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="space-y-1.5">
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-bold">Custom fields</h1>
            <Badge
              variant="outline"
              className="font-mono text-xs font-normal text-muted-foreground"
            >
              {slug} · {defs.length} {defs.length === 1 ? "field" : "fields"}
            </Badge>
          </div>
          <p className="max-w-2xl text-sm text-muted-foreground">
            Schema for every task in this project. Each field has a kind,
            subtype, footer aggregation, and a required flag.
            <br />
            Only space admins can edit definitions.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => setChangeLogOpen(true)}
            data-testid="custom-fields-changelog"
          >
            <History className="mr-1 h-3.5 w-3.5" /> Change log
          </Button>
          {isSpaceAdmin && (
            <Button
              type="button"
              size="sm"
              onClick={openCreate}
              data-testid="custom-field-add"
            >
              <Plus className="mr-1 h-3.5 w-3.5" /> Add field
            </Button>
          )}
        </div>
      </div>

      {!noticeDismissed && (
        <div className="flex items-center gap-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-4 py-3 text-sm">
          <CircleCheck className="h-4 w-4 shrink-0 text-emerald-400" />
          <p className="flex-1 text-muted-foreground">
            {isSpaceAdmin ? (
              <>
                You&apos;re editing as a{" "}
                <span className="font-medium text-foreground">space admin</span>.
                Members in {spaceName ?? "this space"} can use these fields on
                tasks but can&apos;t change definitions.
              </>
            ) : (
              <>
                Only space admins can edit definitions. You can use these fields
                on tasks in {spaceName ?? "this space"}.
              </>
            )}
          </p>
          <button
            type="button"
            onClick={() => setNoticeDismissed(true)}
            className="shrink-0 text-xs font-medium uppercase tracking-wide text-muted-foreground transition hover:text-foreground"
          >
            Dismiss
          </button>
        </div>
      )}

      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-muted-foreground text-sm">Loading…</p>
      ) : defs.length === 0 ? (
        <div className="rounded-lg border border-dashed p-8 text-center">
          <p className="text-muted-foreground text-sm">
            No custom fields yet.
            {isSpaceAdmin
              ? " Add the first one to start collecting structured data on tasks."
              : " Only space admins can add fields."}
          </p>
          {isSpaceAdmin && (
            <Button
              type="button"
              size="sm"
              className="mt-3"
              onClick={openCreate}
            >
              <Plus className="mr-1 h-3.5 w-3.5" /> Add field
            </Button>
          )}
        </div>
      ) : (
        <div className="rounded-lg border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-8" />
                <TableHead>Name</TableHead>
                <TableHead>Kind</TableHead>
                <TableHead>Required</TableHead>
                <TableHead>Footer</TableHead>
                <TableHead className="text-right">Filled</TableHead>
                {isSpaceAdmin && (
                  <TableHead className="w-20 text-right">Actions</TableHead>
                )}
              </TableRow>
            </TableHeader>
            <TableBody>
              <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
              >
                <SortableContext
                  items={defs.map((d) => d["@id"])}
                  strategy={verticalListSortingStrategy}
                >
                  {defs.map((def) => (
                    <FieldRow
                      key={def["@id"]}
                      def={def}
                      total={total}
                      filled={filled[def["@id"]] ?? 0}
                      isSpaceAdmin={isSpaceAdmin}
                      draggable={isSpaceAdmin && defs.length > 1}
                      onEdit={() => openEdit(def)}
                      onDuplicate={() => void handleDuplicate(def)}
                    />
                  ))}
                </SortableContext>
              </DndContext>
            </TableBody>
          </Table>
          {isSpaceAdmin && (
            <button
              type="button"
              onClick={openCreate}
              className="flex w-full items-center gap-1 border-t px-4 py-2.5 text-sm text-muted-foreground hover:bg-muted/50 hover:text-foreground"
              data-testid="custom-field-add-row"
            >
              <Plus className="h-3.5 w-3.5" /> Add another field
            </button>
          )}
        </div>
      )}

      {defs.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Drag rows to reorder. Order here drives column order on the task list.
        </p>
      )}

      <CustomFieldSheet
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        projectIri={projectIri}
        spaceName={spaceName}
        initial={editing ?? undefined}
        initialPosition={nextPosition}
        valueCount={editingValueCount}
        onSaved={handleSaved}
        onDeleted={isSpaceAdmin ? handleDeleted : undefined}
        onDuplicate={isSpaceAdmin ? handleDuplicate : undefined}
      />

      <CustomFieldChangeLog
        open={changeLogOpen}
        onOpenChange={setChangeLogOpen}
        projectId={projectId}
      />
    </div>
  );
};

const FieldRow = ({
  def,
  total,
  filled,
  isSpaceAdmin,
  draggable,
  onEdit,
  onDuplicate,
}: {
  def: CustomFieldDefinition;
  total: number;
  filled: number;
  isSpaceAdmin: boolean;
  draggable: boolean;
  onEdit: () => void;
  onDuplicate: () => void;
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: def["@id"], disabled: !draggable });
  const badge = KIND_BADGE[def.kind];

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(isDragging && "opacity-60")}
      data-testid="custom-field-item"
    >
      <TableCell className="w-8 pr-0">
        {draggable && (
          <button
            type="button"
            className="cursor-grab text-muted-foreground hover:text-foreground"
            aria-label={`Reorder ${def.name}`}
            {...attributes}
            {...listeners}
          >
            <GripVertical className="h-4 w-4" />
          </button>
        )}
      </TableCell>
      <TableCell>
        <div className="flex items-baseline gap-2">
          <span className="font-medium" data-testid="custom-field-name">
            {def.name}
          </span>
          <span className="font-mono text-xs text-muted-foreground">
            {fieldHandle(def.name)}
          </span>
        </div>
      </TableCell>
      <TableCell>
        <span
          className={cn(
            "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium",
            badge.wrap,
          )}
        >
          <span className={cn("h-1.5 w-1.5 rounded-full", badge.dot)} />
          {kindLabelFor(def.kind).toLowerCase()} ·{" "}
          {subtypeLabelFor(def.kind, def.subtype).toLowerCase()}
        </span>
      </TableCell>
      <TableCell>
        {def.nullable ? (
          <span className="inline-flex items-center gap-1.5 rounded-full border border-input bg-muted/40 px-2.5 py-0.5 text-xs text-muted-foreground">
            <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/50" />
            Optional
          </span>
        ) : (
          <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2.5 py-0.5 text-xs font-medium text-emerald-300">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
            Required
          </span>
        )}
      </TableCell>
      <TableCell>
        {def.footer ? (
          <span className="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            <span className="h-3.5 w-3.5 rounded-[3px] border border-muted-foreground/40" />
            {def.footer.kind}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        )}
      </TableCell>
      <TableCell className="text-right tabular-nums text-sm text-muted-foreground">
        {filled} / {total}
      </TableCell>
      {isSpaceAdmin && (
        <TableCell className="text-right">
          <div className="flex items-center justify-end gap-0.5">
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className="h-8 w-8"
              onClick={onEdit}
              aria-label={`Edit ${def.name}`}
              data-testid="custom-field-edit"
            >
              <Settings className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className="h-8 w-8"
              onClick={onDuplicate}
              aria-label={`Duplicate ${def.name}`}
              data-testid="custom-field-duplicate"
            >
              <Copy className="h-3.5 w-3.5" />
            </Button>
          </div>
        </TableCell>
      )}
    </TableRow>
  );
};

export default CustomFieldsManager;
