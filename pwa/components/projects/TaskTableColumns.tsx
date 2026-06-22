import type { CustomFieldDefinition } from "@/components/custom-fields/types";

/**
 * Shared `<colgroup>` for the project list view's tables, so the per-section
 * tables and the grand-total bar line up column-for-column. Every table that
 * uses it must be `table-fixed`. Column order mirrors the section table:
 *
 *   checkbox · # · Task · Tags · [custom fields…] · Due · Assignees · actions
 *
 * The Task column carries no width so `table-fixed` gives it the remaining
 * space; everything else is fixed so the columns are identical across tables.
 */
const TaskTableColumns = ({
  definitions,
}: {
  definitions: CustomFieldDefinition[];
}) => (
  <colgroup>
    <col className="w-8" />
    <col className="w-10" />
    <col />
    <col className="w-44" />
    {definitions.map((d) => (
      <col key={d["@id"]} className="w-32" />
    ))}
    <col className="w-32" />
    <col className="w-44" />
    <col className="w-10" />
  </colgroup>
);

export default TaskTableColumns;
