/**
 * Tree helpers for the sidebar Pages navigator (nest + rearrange). Pages carry
 * a self-referential `parent` (IRI) and an integer `position`; these functions
 * flatten / rebuild that tree and compute the drop projection for the dnd-kit
 * drag interaction (drag vertically to reorder, horizontally to nest/outdent).
 *
 * The algorithm mirrors dnd-kit's canonical "SortableTree" example, adapted to
 * our IRI-keyed page rows.
 */
import { arrayMove } from "@dnd-kit/sortable";

/** A page row as returned by `/pages?space=…` (page:read). */
export interface PageNode {
  "@id": string;
  id: string;
  title: string;
  /** Parent page IRI (`/pages/{uuid}`) or null for a top-level page. */
  parent: string | null;
  position: number;
}

/** A page flattened out of the tree, carrying its computed depth + parent. */
export interface FlatPage {
  "@id": string;
  id: string;
  title: string;
  parentId: string | null;
  depth: number;
  /** Number of direct children (drives the collapse chevron). */
  childCount: number;
}

interface TreeItem extends PageNode {
  children: TreeItem[];
}

const byOrder = (a: PageNode, b: PageNode): number =>
  a.position - b.position || a.title.localeCompare(b.title);

/** Group flat pages into a nested tree by their `parent` IRI. */
export const buildTree = (pages: PageNode[]): TreeItem[] => {
  const nodes = new Map<string, TreeItem>();
  for (const p of pages) nodes.set(p["@id"], { ...p, children: [] });

  const roots: TreeItem[] = [];
  for (const node of nodes.values()) {
    const parent = node.parent ? nodes.get(node.parent) : null;
    if (parent) parent.children.push(node);
    else roots.push(node);
  }
  const sort = (items: TreeItem[]) => {
    items.sort(byOrder);
    for (const item of items) sort(item.children);
  };
  sort(roots);
  return roots;
};

/**
 * Flatten a tree into a render list (depth-first), skipping the descendants of
 * any page in `collapsed`.
 */
export const flattenTree = (
  pages: PageNode[],
  collapsed: Set<string> = new Set(),
): FlatPage[] => {
  const tree = buildTree(pages);
  const out: FlatPage[] = [];
  const walk = (items: TreeItem[], depth: number, hidden: boolean) => {
    for (const item of items) {
      if (!hidden) {
        out.push({
          "@id": item["@id"],
          id: item.id,
          title: item.title,
          parentId: item.parent,
          depth,
          childCount: item.children.length,
        });
      }
      walk(item.children, depth + 1, hidden || collapsed.has(item["@id"]));
    }
  };
  walk(tree, 0, false);
  return out;
};

const clamp = (n: number, min: number, max: number): number =>
  Math.min(Math.max(n, min), max);

export interface Projection {
  depth: number;
  parentId: string | null;
}

/**
 * Where the dragged page would land: its projected depth (clamped so it can
 * only nest under the row above or sit between siblings) and resolved parent.
 */
export const getProjection = (
  items: FlatPage[],
  activeIri: string,
  overIri: string,
  dragOffsetX: number,
  indentationWidth: number,
): Projection => {
  const overIndex = items.findIndex((i) => i["@id"] === overIri);
  const activeIndex = items.findIndex((i) => i["@id"] === activeIri);
  const activeItem = items[activeIndex];
  const newItems = arrayMove(items, activeIndex, overIndex);
  const previousItem = newItems[overIndex - 1];
  const nextItem = newItems[overIndex + 1];

  const dragDepth = Math.round(dragOffsetX / indentationWidth);
  const projectedDepth = (activeItem?.depth ?? 0) + dragDepth;
  const maxDepth = previousItem ? previousItem.depth + 1 : 0;
  const minDepth = nextItem ? nextItem.depth : 0;
  const depth = clamp(projectedDepth, minDepth, maxDepth);

  const parentId = (): string | null => {
    if (depth === 0 || !previousItem) return null;
    if (depth === previousItem.depth) return previousItem.parentId;
    if (depth > previousItem.depth) return previousItem["@id"];
    // Walk back to the nearest ancestor at the target depth.
    const ancestor = newItems
      .slice(0, overIndex)
      .reverse()
      .find((i) => i.depth === depth);
    return ancestor?.parentId ?? null;
  };

  return { depth, parentId: parentId() };
};

export interface PageOrderUpdate {
  "@id": string;
  parent: string | null;
  position: number;
}

/**
 * Apply a drop to the full page set and return the pages whose `parent` or
 * `position` changed — the minimal PATCH set to persist. `activeIri` is moved
 * to `overIri`'s slot at the projected `parentId`; siblings are renumbered
 * contiguously from 0.
 */
export const applyMove = (
  pages: PageNode[],
  activeIri: string,
  overIri: string,
  projection: Projection,
): { next: PageNode[]; updates: PageOrderUpdate[] } => {
  const flat = flattenTree(pages); // full order, nothing collapsed
  const activeIndex = flat.findIndex((i) => i["@id"] === activeIri);
  const overIndex = flat.findIndex((i) => i["@id"] === overIri);
  if (activeIndex < 0 || overIndex < 0) return { next: pages, updates: [] };

  const reordered = arrayMove(flat, activeIndex, overIndex);
  const nextParent = new Map<string, string | null>();
  for (const item of reordered) nextParent.set(item["@id"], item.parentId);
  nextParent.set(activeIri, projection.parentId);

  // Renumber each sibling group from its order of appearance in `reordered`.
  const counters = new Map<string, number>();
  const nextPosition = new Map<string, number>();
  for (const item of reordered) {
    const parent = nextParent.get(item["@id"]) ?? null;
    const key = parent ?? "__root__";
    const n = counters.get(key) ?? 0;
    nextPosition.set(item["@id"], n);
    counters.set(key, n + 1);
  }

  const updates: PageOrderUpdate[] = [];
  const next = pages.map((p) => {
    const parent = nextParent.get(p["@id"]) ?? null;
    const position = nextPosition.get(p["@id"]) ?? p.position;
    if (parent !== p.parent || position !== p.position) {
      updates.push({ "@id": p["@id"], parent, position });
    }
    return { ...p, parent, position };
  });

  return { next, updates };
};
