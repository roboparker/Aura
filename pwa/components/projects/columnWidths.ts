import type { ListColumn } from "@/components/projects/listColumns";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";

/**
 * Content-aware column widths for the project list view.
 *
 * The list is several separate `table-fixed` tables (sticky header, one per
 * section, grand-total) that share a `<colgroup>`, so their columns only line
 * up if every table is given the *same* widths. That rules out the browser's
 * own `table-auto` sizing (each table would size independently and desync).
 *
 * So we size them ourselves: each column wants to be wide enough for its
 * widest content (and its header), clamped to per-column bounds. If the total
 * fits the container, the leftover stays as the trailing gutter — columns have
 * "room to grow". If it doesn't fit, the growable columns are scaled back down
 * toward their minimums so the table never overflows ("no room to grow", and
 * the cells' `truncate` takes over).
 */

/** Just the bits of a task these widths read. */
export interface WidthTask {
  title: string;
  dueDate: string | null;
  assignees: unknown[];
  tags: { title: string }[];
  customFieldValues: { definition: string; value: unknown }[];
}

// Rough metrics for the text-sm table cells (px). Heuristic, not exact — the
// goal is proportional "grow with content", and `truncate` covers any slack.
const CHAR_PX = 7.3;
const CELL_PAD_PX = 28; // px-2 on both sides + breathing room
const HEADER_EXTRA_PX = 40; // sort caret + filter trigger in the header cell
const CHECKBOX_PX = 64; // the leading w-16 col

interface Bounds {
  min: number;
  max: number;
  /** Growable columns give back width first when there's no room. */
  grow: boolean;
}

const BUILTIN_BOUNDS: Record<string, Bounds> = {
  task: { min: 200, max: 560, grow: true },
  due: { min: 132, max: 188, grow: false },
  assignees: { min: 72, max: 176, grow: false },
  tags: { min: 120, max: 340, grow: true },
};
const CF_BOUNDS: Bounds = { min: 120, max: 340, grow: true };

const boundsFor = (col: ListColumn): Bounds =>
  BUILTIN_BOUNDS[col.key] ?? CF_BOUNDS;

const clamp = (min: number, v: number, max: number): number =>
  Math.max(min, Math.min(max, v));

/** Best-effort display text for a custom-field value (for width only). */
const cfText = (def: CustomFieldDefinition | undefined, value: unknown): string => {
  if (def == null || value == null || value === "") return "";
  switch (def.kind) {
    case "boolean":
      return value === true ? "Yes" : "No";
    case "text":
      return String(value);
    case "numeric":
      if (def.subtype === "money" && typeof value === "object" && value !== null) {
        const amount = (value as { amount?: unknown }).amount;
        const currency = (value as { currency?: unknown }).currency;
        return `${typeof amount === "number" ? amount / 100 : ""} ${
          typeof currency === "string" ? currency : ""
        }`;
      }
      return String(value);
    case "date":
      return typeof value === "string" ? value.slice(0, 10) : "";
    case "select": {
      const options = def.config.options ?? [];
      const labelFor = (k: unknown): string =>
        options.find((o) => o.key === k)?.label ?? String(k ?? "");
      return Array.isArray(value)
        ? value.map(labelFor).join(", ")
        : labelFor(value);
    }
    case "reference":
      // The display label isn't known client-side; assume a moderate width.
      return "reference value";
    default:
      return "";
  }
};

/** Character count of one task's content in a column (0 = sized another way). */
const contentChars = (col: ListColumn, task: WidthTask): number => {
  switch (col.key) {
    case "task":
      return task.title.length;
    case "due":
      return task.dueDate ? 14 : 8;
    case "assignees":
      return 0; // sized by avatar count, not text
    case "tags":
      return task.tags.map((t) => t.title).join(", ").length;
    default: {
      const iri = col.definition?.["@id"];
      const value = task.customFieldValues.find((v) => v.definition === iri)?.value;
      return cfText(col.definition, value).length;
    }
  }
};

/**
 * Compute `{ columnKey -> "<px>px" }` widths for the list colgroup. Returns
 * `undefined` until the container width is known (callers fall back to the
 * static `widthClass`).
 */
export const computeColumnWidths = (
  columns: ListColumn[],
  tasks: WidthTask[],
  availableWidthPx: number,
): Record<string, string> | undefined => {
  if (!Number.isFinite(availableWidthPx) || availableWidthPx <= 0) return undefined;

  const desired: Record<string, number> = {};
  for (const col of columns) {
    const bounds = boundsFor(col);
    // The header name should never be cut off when there's room, so it sets a
    // floor that ignores the per-column content cap (the cap only stops long
    // *values* from ballooning a column — not the column's own name).
    const headerPx =
      col.label.length * CHAR_PX + CELL_PAD_PX + HEADER_EXTRA_PX;
    let contentPx = 0;
    if (col.key === "assignees") {
      let maxCount = 0;
      for (const t of tasks) maxCount = Math.max(maxCount, t.assignees.length);
      contentPx = 44 + Math.min(maxCount, 3) * 22 + (maxCount > 3 ? 30 : 0);
    } else {
      for (const t of tasks) {
        contentPx = Math.max(contentPx, contentChars(col, t) * CHAR_PX + CELL_PAD_PX);
      }
    }
    desired[col.key] = Math.max(
      bounds.min,
      headerPx,
      clamp(bounds.min, contentPx, bounds.max),
    );
  }

  // Fit to the container: if the columns overshoot, claw width back from the
  // growable ones (down to their minimums) so the table doesn't overflow.
  const total =
    CHECKBOX_PX + columns.reduce((sum, c) => sum + desired[c.key], 0);
  if (total > availableWidthPx) {
    const overflow = total - availableWidthPx;
    const growable = columns.filter((c) => boundsFor(c).grow);
    const pool = growable.reduce(
      (sum, c) => sum + (desired[c.key] - boundsFor(c).min),
      0,
    );
    if (pool > 0) {
      const ratio = Math.min(1, overflow / pool);
      for (const c of growable) {
        desired[c.key] -= (desired[c.key] - boundsFor(c).min) * ratio;
      }
    }
  }

  const out: Record<string, string> = {};
  for (const col of columns) out[col.key] = `${Math.round(desired[col.key])}px`;
  return out;
};
