import { Checkbox } from "@/components/ui/checkbox";
import { cn } from "@/lib/utils";
import {
  ACTION_LABELS,
  CATEGORY_LABELS,
  PERMISSION_ACTIONS,
  PERMISSION_CATEGORIES,
  matrixAllows,
  type PermissionAction,
  type PermissionCategory,
  type PermissionMatrix,
} from "@/lib/roleTypes";

/**
 * Editor for a role's permission matrix (#space-roles): one row per content
 * category, one column per CRUD action, plus a per-row and master All toggle.
 * Controlled — `value` is a `{category: {action: bool}}` map.
 */
const RolePermissionMatrix = ({
  value,
  onChange,
  disabled = false,
}: {
  value: PermissionMatrix;
  onChange: (next: PermissionMatrix) => void;
  disabled?: boolean;
}) => {
  const setCell = (
    category: PermissionCategory,
    action: PermissionAction,
    granted: boolean,
  ) => {
    onChange({ ...value, [category]: { ...value[category], [action]: granted } });
  };

  const fullRow = (granted: boolean): Record<PermissionAction, boolean> => ({
    create: granted,
    read: granted,
    update: granted,
    delete: granted,
  });

  const setRow = (category: PermissionCategory, granted: boolean) => {
    onChange({ ...value, [category]: fullRow(granted) });
  };

  const setAll = (granted: boolean) => {
    const next: PermissionMatrix = {};
    for (const category of PERMISSION_CATEGORIES) {
      next[category] = fullRow(granted);
    }
    onChange(next);
  };

  const rowAll = (category: PermissionCategory): boolean =>
    PERMISSION_ACTIONS.every((action) => matrixAllows(value, category, action));
  const allOn = PERMISSION_CATEGORIES.every((c) => rowAll(c));

  const headCell = "px-2 py-1.5 text-center text-xs font-medium text-muted-foreground";

  return (
    <div className="overflow-x-auto rounded-md border" data-testid="role-permission-matrix">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b bg-muted/40">
            <th className="px-3 py-1.5 text-left text-xs font-medium text-muted-foreground">
              Content type
            </th>
            {PERMISSION_ACTIONS.map((action) => (
              <th key={action} className={headCell}>
                {ACTION_LABELS[action]}
              </th>
            ))}
            <th className={headCell}>
              <button
                type="button"
                disabled={disabled}
                onClick={() => setAll(!allOn)}
                className="rounded px-1.5 py-0.5 text-xs hover:bg-accent disabled:opacity-50"
              >
                {allOn ? "None" : "All"}
              </button>
            </th>
          </tr>
        </thead>
        <tbody>
          {PERMISSION_CATEGORIES.map((category) => {
            const rowOn = rowAll(category);
            return (
              <tr key={category} className="border-b last:border-0">
                <td className="px-3 py-1.5 font-medium">{CATEGORY_LABELS[category]}</td>
                {PERMISSION_ACTIONS.map((action) => (
                  <td key={action} className="px-2 py-1.5 text-center">
                    <Checkbox
                      checked={matrixAllows(value, category, action)}
                      disabled={disabled}
                      onCheckedChange={(c) => setCell(category, action, c === true)}
                      aria-label={`${CATEGORY_LABELS[category]} — ${ACTION_LABELS[action]}`}
                      className="size-4"
                    />
                  </td>
                ))}
                <td className="px-2 py-1.5 text-center">
                  <button
                    type="button"
                    disabled={disabled}
                    onClick={() => setRow(category, !rowOn)}
                    className={cn(
                      "rounded px-1.5 py-0.5 text-xs hover:bg-accent disabled:opacity-50",
                      rowOn ? "text-muted-foreground" : "text-foreground",
                    )}
                  >
                    {rowOn ? "None" : "All"}
                  </button>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};

export default RolePermissionMatrix;
