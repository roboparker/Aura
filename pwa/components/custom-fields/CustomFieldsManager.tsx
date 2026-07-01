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
import { Copy, GripVertical, History, Pencil, Plus, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
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
import ConfirmDialog from "@/components/common/ConfirmDialog";
import { fieldHandle } from "./handle";
import { KIND_BADGE, kindLabelFor, subtypeLabelFor } from "./kind-editors";
import type { CustomFieldDefinition, FieldStatsResponse } from "./types";

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
  /** Space that owns the fields (#custom-fields-space). Drives load + create. */
  spaceIri: string;
  /** Header `slug · N fields` badge label (space or project title). */
  projectTitle: string;
  /** When mounted in a project's Settings tab, enables drag-reorder (via the
   *  project route) + the FILLED stat. Omit on the space-level manager. */
  projectIri?: string;
  /** Active space name, surfaced in the admin notice + reference scope note. */
  spaceName?: string;
  isSpaceAdmin: boolean;
  /** Fired after any definition mutation (create/edit/delete/reorder) so a
   *  host that also renders the definitions (e.g. task columns) can re-sync. */
  onDefinitionsChanged?: () => void;
}

const projectIdFromIri = (iri: string): string => iri.split("/").pop() ?? "";

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
  spaceIri,
  projectIri,
  spaceName,
  isSpaceAdmin,
  onDefinitionsChanged,
}: Props) => {
  const projectId = projectIri ? projectIdFromIri(projectIri) : null;
  const [defs, setDefs] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [filled, setFilled] = useState<Record<string, number>>({});
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editing, setEditing] = useState<CustomFieldDefinition | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<CustomFieldDefinition | null>(
    null,
  );
  // Bumped on every open so the drawer remounts fresh each time. Reusing one
  // Radix Dialog instance and toggling `open` back on while its close (exit)
  // animation is still pending leaves Radix's Presence state machine stuck and
  // the drawer never reopens — reproducible by saving a field then immediately
  // clicking Edit. A fresh mount per open sidesteps the stale Presence.
  const [openSeq, setOpenSeq] = useState(0);
  const [changeLogOpen, setChangeLogOpen] = useState(false);

  const sensors = useSensors(useSensor(PointerSensor));

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const url = `${ENTRYPOINT}/custom_field_definitions?space=${encodeURIComponent(spaceIri)}`;
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
  }, [spaceIri]);

  const loadStats = useCallback(async () => {
    if (null === projectId) return;
    try {
      const res = await fetch(
        `${ENTRYPOINT}/projects/${projectId}/custom_field_stats`,
        { credentials: "include", headers: { Accept: "application/json" } },
      );
      if (!res.ok) return;
      const data: FieldStatsResponse = await res.json();
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


  const openCreate = () => {
    setEditing(null);
    setOpenSeq((n) => n + 1);
    setSheetOpen(true);
  };
  const openEdit = (def: CustomFieldDefinition) => {
    setEditing(def);
    setOpenSeq((n) => n + 1);
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
    onDefinitionsChanged?.();
  };

  const handleDeleted = (def: CustomFieldDefinition) => {
    setDefs((prev) => prev.filter((d) => d["@id"] !== def["@id"]));
    onDefinitionsChanged?.();
  };

  // Deletion is confirmed through the shared ConfirmDialog; the action throws
  // on failure so the modal surfaces the error and stays open.
  const confirmDelete = async (def: CustomFieldDefinition) => {
    const res = await fetch(`${ENTRYPOINT}${def["@id"]}`, {
      method: "DELETE",
      credentials: "include",
    });
    if (!res.ok) throw new Error(await errorMessage(res));
    handleDeleted(def);
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
            visibility: def.visibility,
            space: spaceIri,
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
    [spaceIri, nextPosition],
  );

  const persistOrder = useCallback(
    async (ordered: CustomFieldDefinition[]) => {
      if (null === projectId) return;
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
        onDefinitionsChanged?.();
      } catch (err) {
        setLoadError(
          err instanceof Error ? err.message : "Failed to save order.",
        );
        void load(); // re-sync to the server's truth
      }
    },
    [projectId, load, onDefinitionsChanged],
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
              data-testid="custom-field-add"
            >
              <Plus className="mr-1 h-3.5 w-3.5" /> Add field
            </Button>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border bg-card">
          {/* Change log is project-scoped activity; only shown in a
              project context (not on the space-level manager). */}
          {projectId && (
            <div className="flex items-center justify-end border-b px-2 py-1">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 text-muted-foreground hover:text-foreground"
                onClick={() => setChangeLogOpen(true)}
                aria-label="Custom fields change log"
                title="Change log"
                data-testid="custom-fields-changelog"
              >
                <History className="h-4 w-4" />
              </Button>
            </div>
          )}
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-8" />
                <TableHead>Name</TableHead>
                <TableHead>Kind</TableHead>
                <TableHead>Required</TableHead>
                <TableHead>Footer</TableHead>
                {isSpaceAdmin && (
                  <TableHead className="w-28 text-right">Actions</TableHead>
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
                      isSpaceAdmin={isSpaceAdmin}
                      draggable={isSpaceAdmin && defs.length > 1}
                      onEdit={() => openEdit(def)}
                      onDuplicate={() => void handleDuplicate(def)}
                      onDelete={() => setDeleteTarget(def)}
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
        key={openSeq}
        open={sheetOpen}
        onOpenChange={setSheetOpen}
        spaceIri={spaceIri}
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

      {deleteTarget && (
        <ConfirmDialog
          open
          onOpenChange={(next) => {
            if (!next) setDeleteTarget(null);
          }}
          title="Delete custom field"
          description={
            <>
              Delete custom field &ldquo;
              <span className="font-medium">{deleteTarget.name}</span>&rdquo;?
              Existing values on tasks will also be removed.
            </>
          }
          confirmLabel="Delete"
          destructive
          onConfirm={() => confirmDelete(deleteTarget)}
        />
      )}
    </div>
  );
};

const FieldRow = ({
  def,
  isSpaceAdmin,
  draggable,
  onEdit,
  onDuplicate,
  onDelete,
}: {
  def: CustomFieldDefinition;
  isSpaceAdmin: boolean;
  draggable: boolean;
  onEdit: () => void;
  onDuplicate: () => void;
  onDelete: () => void;
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
            "inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-medium",
            badge.wrap,
          )}
        >
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
      {isSpaceAdmin && (
        <TableCell className="text-right">
          <div className="flex items-center justify-end gap-0.5">
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
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className="h-8 w-8"
              onClick={onEdit}
              aria-label={`Edit ${def.name}`}
              data-testid="custom-field-edit"
            >
              <Pencil className="h-3.5 w-3.5" />
            </Button>
            <Button
              type="button"
              size="icon"
              variant="ghost"
              className="h-8 w-8 text-muted-foreground hover:text-destructive"
              onClick={onDelete}
              aria-label={`Delete ${def.name}`}
              data-testid="custom-field-delete"
            >
              <Trash2 className="h-3.5 w-3.5" />
            </Button>
          </div>
        </TableCell>
      )}
    </TableRow>
  );
};

export default CustomFieldsManager;
