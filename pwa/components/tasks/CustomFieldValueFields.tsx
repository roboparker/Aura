import { Badge } from "@/components/ui/badge";
import type { AvatarUser } from "@/components/user/UserAvatar";
import { violationFor, type ViolationMap } from "@/lib/violations";
import { subtypeLabelFor } from "@/components/custom-fields/kind-editors";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import { CustomFieldValueEditor } from "./value-editors";

interface CustomFieldValueFieldsProps {
  definitions: CustomFieldDefinition[];
  /** Working values keyed by definition IRI. */
  values: Record<string, unknown>;
  onChange: (defIri: string, next: unknown) => void;
  /** Validation messages keyed by definition IRI. */
  violations?: ViolationMap;
  projectIri?: string | null;
  spaceIri?: string | null;
  users?: AvatarUser[];
  disabled?: boolean;
  /** Compact mode (e.g. the new-task form): drop the per-field subtype badge
   *  and editor adornments, for a tighter inline form. */
  compact?: boolean;
}

/**
 * Controlled list of per-definition custom-field value editors — the shared
 * body of the task drawer's auto-saving CustomFieldValueList and the project
 * page's new-task form. Purely presentational: it holds no state and never
 * persists, so the parent owns the working copy and decides when to save.
 */
const CustomFieldValueFields = ({
  definitions,
  values,
  onChange,
  violations = {},
  projectIri,
  spaceIri,
  users,
  disabled,
  compact,
}: CustomFieldValueFieldsProps) => (
  <div className="space-y-4">
    {definitions.map((def) => (
      <div key={def["@id"]} className="space-y-1.5">
        <div className="flex items-center justify-between gap-2">
          <label className="text-sm font-medium">
            {def.name}
            {!def.nullable && (
              <span className="ml-0.5 text-destructive" aria-hidden>
                *
              </span>
            )}
          </label>
          {!compact && (
            <Badge variant="outline" className="shrink-0 text-[10px] uppercase">
              {subtypeLabelFor(def.kind, def.subtype)}
            </Badge>
          )}
        </div>
        <CustomFieldValueEditor
          definition={def}
          value={values[def["@id"]] ?? null}
          onChange={(next) => onChange(def["@id"], next)}
          error={violationFor(violations, def["@id"])}
          disabled={disabled}
          projectIri={projectIri}
          spaceIri={spaceIri}
          users={users}
          compact={compact}
        />
      </div>
    ))}
  </div>
);

export default CustomFieldValueFields;
