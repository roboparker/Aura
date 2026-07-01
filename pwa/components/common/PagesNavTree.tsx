import Link from "next/link";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronRight, GripVertical } from "lucide-react";
import {
  DndContext,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DragMoveEvent,
  type DragOverEvent,
  type DragStartEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import {
  applyMove,
  flattenTree,
  getProjection,
  type FlatPage,
  type PageNode,
} from "@/lib/pageTree";

const INDENT = 14; // px per nesting level

interface Props {
  spaceIri: string;
  currentPath: string;
  wrap: (children: ReactNode) => ReactNode;
  /** Foot-of-section "New page" link (rendered after the tree). */
  addLink: ReactNode;
}

/**
 * Sidebar Pages navigator with drag-to-reorder and drag-to-nest (#pages-tree).
 * Pages carry `parent` + `position`; dragging vertically reorders siblings and
 * dragging horizontally nests under the row above / outdents. Drops persist via
 * `PATCH /pages/{id}` ({parent, position}); the API enforces edit rights, so a
 * failed move reverts by refetching.
 */
const PagesNavTree = ({ spaceIri, currentPath, wrap, addLink }: Props) => {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: ["pages", spaceIri, "nav-tree"],
    enabled: !!spaceIri,
    queryFn: () =>
      apiGetCollection<PageNode>(
        `/pages?space=${encodeURIComponent(spaceIri)}&itemsPerPage=100`,
        { errorMessage: "Failed to load pages." },
      ),
  });

  const [items, setItems] = useState<PageNode[]>([]);
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
  const [activeId, setActiveId] = useState<string | null>(null);
  const [overId, setOverId] = useState<string | null>(null);
  const [offsetLeft, setOffsetLeft] = useState(0);

  // Sync local order from the query, but not mid-drag (would fight the
  // optimistic update).
  useEffect(() => {
    if (data && !activeId) setItems(data);
  }, [data, activeId]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
  );

  // While dragging, hide the dragged page's own descendants so it can't be
  // nested inside itself and the projection maths stay sane.
  const flattened = useMemo<FlatPage[]>(() => {
    const flat = flattenTree(items, collapsed);
    if (!activeId) return flat;
    const excluded = new Set<string>();
    const collect = (parentIri: string) => {
      for (const p of items) {
        if (p.parent === parentIri && !excluded.has(p["@id"])) {
          excluded.add(p["@id"]);
          collect(p["@id"]);
        }
      }
    };
    collect(activeId);
    return flat.filter((i) => !excluded.has(i["@id"]));
  }, [items, collapsed, activeId]);

  const projected =
    activeId && overId
      ? getProjection(flattened, activeId, overId, offsetLeft, INDENT)
      : null;

  const toggleCollapse = (iri: string) =>
    setCollapsed((prev) => {
      const next = new Set(prev);
      if (next.has(iri)) next.delete(iri);
      else next.add(iri);
      return next;
    });

  const onDragStart = ({ active }: DragStartEvent) => {
    setActiveId(String(active.id));
    setOverId(String(active.id));
    setOffsetLeft(0);
  };
  const onDragMove = ({ delta }: DragMoveEvent) => setOffsetLeft(delta.x);
  const onDragOver = ({ over }: DragOverEvent) =>
    setOverId(over ? String(over.id) : null);

  const reset = () => {
    setActiveId(null);
    setOverId(null);
    setOffsetLeft(0);
  };

  const onDragEnd = async ({ active, over }: DragEndEvent) => {
    const activeIri = String(active.id);
    const overIri = over ? String(over.id) : null;
    const projection = projected;
    reset();
    if (!overIri || !projection) return;

    const { next, updates } = applyMove(items, activeIri, overIri, projection);
    if (updates.length === 0) return;
    setItems(next); // optimistic

    try {
      await Promise.all(
        updates.map((u) =>
          apiSend("PATCH", u["@id"], {
            contentType: "application/merge-patch+json",
            errorMessage: "Failed to move page.",
            body: { parent: u.parent, position: u.position },
          }),
        ),
      );
    } catch {
      // The API rejected a move (e.g. a page you can't edit) — resync truth.
    } finally {
      void queryClient.invalidateQueries({ queryKey: ["pages"] });
    }
  };

  if (items.length === 0) return <>{addLink}</>;

  const sortedIds = flattened.map((i) => i["@id"]);

  return (
    <>
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragStart={onDragStart}
        onDragMove={onDragMove}
        onDragOver={onDragOver}
        onDragEnd={(e) => void onDragEnd(e)}
        onDragCancel={reset}
      >
        <SortableContext items={sortedIds} strategy={verticalListSortingStrategy}>
          {flattened.map((item) => (
            <PageRow
              key={item["@id"]}
              item={item}
              depth={
                item["@id"] === activeId && projected
                  ? projected.depth
                  : item.depth
              }
              collapsed={collapsed.has(item["@id"])}
              currentPath={currentPath}
              onToggle={() => toggleCollapse(item["@id"])}
              wrap={wrap}
            />
          ))}
        </SortableContext>
      </DndContext>
      {addLink}
    </>
  );
};

const PageRow = ({
  item,
  depth,
  collapsed,
  currentPath,
  onToggle,
  wrap,
}: {
  item: FlatPage;
  depth: number;
  collapsed: boolean;
  currentPath: string;
  onToggle: () => void;
  wrap: (children: ReactNode) => ReactNode;
}) => {
  const { setNodeRef, attributes, listeners, transform, transition, isDragging } =
    useSortable({ id: item["@id"] });
  const href = `/pages/${item.id}`;
  const active = currentPath === href;

  return (
    <div
      ref={setNodeRef}
      style={{
        transform: CSS.Transform.toString(transform),
        transition,
        paddingLeft: depth * INDENT,
      }}
      className={cn(
        "group/pagerow flex items-center rounded-md",
        active && "bg-accent text-accent-foreground",
        isDragging && "opacity-60",
      )}
      data-testid="pages-nav-row"
    >
      {item.childCount > 0 ? (
        <button
          type="button"
          onClick={onToggle}
          aria-label={collapsed ? "Expand" : "Collapse"}
          className="shrink-0 rounded p-0.5 text-muted-foreground hover:text-foreground"
        >
          <ChevronRight
            className={cn("size-3.5 transition-transform", !collapsed && "rotate-90")}
          />
        </button>
      ) : (
        <span className="w-[1.375rem] shrink-0" aria-hidden />
      )}

      <button
        type="button"
        aria-label={`Reorder ${item.title}`}
        className="shrink-0 cursor-grab rounded p-0.5 text-muted-foreground/50 opacity-0 hover:text-foreground group-hover/pagerow:opacity-100"
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-3.5" />
      </button>

      {wrap(
        <Button
          asChild
          variant="ghost"
          size="sm"
          className={cn(
            "h-8 w-full min-w-0 justify-start font-normal",
            active && "bg-transparent",
          )}
        >
          <Link href={href}>
            <span className="truncate">{item.title}</span>
          </Link>
        </Button>,
      )}
    </div>
  );
};

export { PagesNavTree };
export default PagesNavTree;
