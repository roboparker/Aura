import type { ListColumn } from "@/components/projects/listColumns";

/**
 * Shared `<colgroup>` for the project list view's tables, so the per-section
 * tables and the grand-total bar line up column-for-column. Every table that
 * uses it must be `table-fixed`. Layout:
 *
 *   checkbox · # · [data columns, in the user's order] · actions
 *
 * The data columns (Task / Due / Assignees / Tags / custom fields) carry
 * their own width class; Task's is "" so `table-fixed` gives it the slack.
 */
const TaskTableColumns = ({ columns }: { columns: ListColumn[] }) => (
  <colgroup>
    <col className="w-8" />
    <col className="w-10" />
    {columns.map((column) => (
      <col key={column.key} className={column.widthClass} />
    ))}
    <col className="w-10" />
  </colgroup>
);

export default TaskTableColumns;
