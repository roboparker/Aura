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
import { GripVertical, History, Pencil, Plus } from "lucide-react";
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
import { kindLabelFor, subtypeLabelFor } from "./kind-editors";
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
  /** Active space name, surfaced in the reference editor scope note. */
  spaceName?: string;
  isSpaceAdmin: boolean;
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

const CustomFieldsManager = ({ projectIri, spaceName, isSpaceAdmin }: Props) => {
  const projectId = projectIdFromIri(projectIri);
  const [defs, setDefs] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [total, setTotal] = useState(0);
  const [filled, setFilled] = useState<Record<string, number>>({});
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editing, setEditing] = useState<CustomFieldDefinition | null>(null);
  const [changeLogOpen, setChangeLogOpen] = useState(false);

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
    <div className="space-y-4" data-testid="custom-fields-manager">
      <div className="flex items-center justify-end gap-2">
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
                {isSpaceAdmin && <TableHead className="w-16 text-right">Actions</TableHead>}
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
}: {
  def: CustomFieldDefinition;
  total: number;
  filled: number;
  isSpaceAdmin: boolean;
  draggable: boolean;
  onEdit: () => void;
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: def["@id"], disabled: !draggable });

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
        <div className="font-medium" data-testid="custom-field-name">
          {def.name}
        </div>
        <div className="font-mono text-xs text-muted-foreground">
          {fieldHandle(def.name)}
        </div>
      </TableCell>
      <TableCell>
        <Badge variant="outline" className="font-normal">
          {kindLabelFor(def.kind).toLowerCase()} ·{" "}
          {subtypeLabelFor(def.kind, def.subtype).toLowerCase()}
        </Badge>
      </TableCell>
      <TableCell>
        {def.nullable ? (
          <span className="text-sm text-muted-foreground">Optional</span>
        ) : (
          <Badge variant="secondary">Required</Badge>
        )}
      </TableCell>
      <TableCell>
        {def.footer ? (
          <span className="text-sm font-medium uppercase">
            {def.footer.kind}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        )}
      </TableCell>
      <TableCell className="text-right tabular-nums text-sm text-muted-foreground">
        {filled}/{total}
      </TableCell>
      {isSpaceAdmin && (
        <TableCell className="text-right">
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={onEdit}
            aria-label={`Edit ${def.name}`}
            data-testid="custom-field-edit"
          >
            <Pencil className="h-3.5 w-3.5" />
          </Button>
        </TableCell>
      )}
    </TableRow>
  );
};

export default CustomFieldsManager;
