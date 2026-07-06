import type { ListColumn } from "@/components/boards/listColumns";

/**
 * Shared `<colgroup>` for the board list view's tables, so the per-section
 * tables and the grand-total bar line up column-for-column. Every table that
 * uses it must be `table-fixed`. Layout:
 *
 *   checkbox · [data columns, in the user's order] · actions
 *
 * Widths: when `widths` is supplied (content-aware, computed once for the whole
 * list so every table matches — see `computeColumnWidths`), each data column
 * gets an explicit pixel width and grows to its content up to the available
 * room. Until that's known, each column falls back to its static `widthClass`.
 * The trailing column never carries a width — it absorbs leftover space as the
 * empty gutter on the right.
 */
const TaskTableColumns = ({
  columns,
  widths,
}: {
  columns: ListColumn[];
  widths?: Record<string, string>;
}) => (
  <colgroup>
    {/* Wide enough to indent the checkbox under the section title text, with
        the section's collapse chevron sitting in the gutter to its left. */}
    <col className="w-16" />
    {columns.map((column) => {
      const width = widths?.[column.key];
      return (
        <col
          key={column.key}
          className={width ? undefined : column.widthClass}
          style={width ? { width } : undefined}
        />
      );
    })}
    {/* Trailing column: no width = absorbs the remaining space as an empty
        gutter on the right; its header carries the "add field" control. */}
    <col />
  </colgroup>
);

export default TaskTableColumns;
