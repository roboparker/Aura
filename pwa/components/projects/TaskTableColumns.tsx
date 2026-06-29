import type { ListColumn } from "@/components/projects/listColumns";

/**
 * Shared `<colgroup>` for the project list view's tables, so the per-section
 * tables and the grand-total bar line up column-for-column. Every table that
 * uses it must be `table-fixed`. Layout:
 *
 *   checkbox · [data columns, in the user's order] · actions
 *
 * The data columns (Task / Due / Assignees / Tags / custom fields) carry
 * their own width class; Task's is "" so `table-fixed` gives it the slack.
 */
const TaskTableColumns = ({ columns }: { columns: ListColumn[] }) => (
  <colgroup>
    {/* Wide enough to indent the checkbox under the section title text, with
        the section's collapse chevron sitting in the gutter to its left. */}
    <col className="w-16" />
    {columns.map((column) => (
      <col key={column.key} className={column.widthClass} />
    ))}
    {/* Trailing column: no width = absorbs the remaining space as an empty
        gutter on the right; its header carries the "add field" control. */}
    <col />
  </colgroup>
);

export default TaskTableColumns;
