import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Badge } from "@/components/ui/badge";
import { Alert, AlertDescription } from "@/components/ui/alert";
import type { AvatarUser } from "@/components/user/UserAvatar";
import { violationFor, type ViolationMap } from "@/lib/violations";
import { subtypeLabelFor } from "@/components/custom-fields/kind-editors";
import type { CustomFieldDefinition } from "@/components/custom-fields/types";
import { CustomFieldValueEditor } from "./value-editors";

/** One `{definition, value}` pair as carried on `Task.customFieldValues`. */
export interface CustomFieldValuePair {
  definition: string;
  value: unknown;
}

interface Props {
  definitions: CustomFieldDefinition[];
  values: CustomFieldValuePair[];
  /**
   * Persist the full value array. Returns the raw API-Platform violation
   * map (keyed by propertyPath); `{}` means success. The list maps those
   * paths back onto the field rows it built the array from.
   */
  onSave: (next: CustomFieldValuePair[]) => Promise<ViolationMap>;
  projectIri?: string | null;
  spaceIri?: string | null;
  users?: AvatarUser[];
  disabled?: boolean;
}

const isEmpty = (value: unknown): boolean =>
  value === null ||
  value === undefined ||
  value === "" ||
  (Array.isArray(value) && value.length === 0);

/**
 * The CUSTOM FIELDS section of the task drawer: one editable row per
 * project definition, with inline 422 validation. Holds a working copy of
 * the values and persists the whole array (debounced) so typing feels
 * inline; orphaned values are dropped so the server reaps them.
 */
const CustomFieldValueList = ({
  definitions,
  values,
  onSave,
  projectIri,
  spaceIri,
  users,
  disabled,
}: Props) => {
  // Working copy keyed by definition IRI.
  const initial = useMemo(() => {
    const map: Record<string, unknown> = {};
    for (const v of values) map[v.definition] = v.value;
    return map;
  }, [values]);

  const [working, setWorking] = useState<Record<string, unknown>>(initial);
  const [violations, setViolations] = useState<ViolationMap>({});
  const [saving, setSaving] = useState(false);
  const dirtyRef = useRef(false);

  // Re-sync from the server copy when it changes and we have no pending
  // local edits (avoids clobbering in-flight typing).
  useEffect(() => {
    if (!dirtyRef.current) setWorking(initial);
  }, [initial]);

  const handleChange = (defIri: string, next: unknown) => {
    dirtyRef.current = true;
    setWorking((prev) => ({ ...prev, [defIri]: next }));
  };

  const persist = useCallback(async () => {
    // Build the array in definition order, dropping empties so the server
    // orphan-removes cleared values. Remember the index→definition map so
    // `customFieldValues[i]` violations land on the right row.
    const payload: CustomFieldValuePair[] = [];
    const indexToDef: string[] = [];
    for (const def of definitions) {
      const value = working[def["@id"]];
      if (isEmpty(value)) continue;
      indexToDef.push(def["@id"]);
      payload.push({ definition: def["@id"], value });
    }

    setSaving(true);
    try {
      const raw = await onSave(payload);
      // Re-key violations from customFieldValues[i] onto definition IRIs.
      const mapped: ViolationMap = {};
      let collectionLevel: string | null = null;
      for (const [path, message] of Object.entries(raw)) {
        const match = path.match(/^customFieldValues\[(\d+)\]/);
        if (match) {
          const defIri = indexToDef[Number(match[1])];
          if (defIri) mapped[defIri] = message;
        } else if (path === "customFieldValues" || path.startsWith("customFieldValues.")) {
          collectionLevel = message;
        }
      }
      if (collectionLevel) mapped.__collection = collectionLevel;
      setViolations(mapped);
      if (Object.keys(raw).length === 0) {
        dirtyRef.current = false;
      }
    } finally {
      setSaving(false);
    }
  }, [definitions, working, onSave]);

  // Debounced persist of the working copy.
  useEffect(() => {
    if (!dirtyRef.current) return;
    const handle = setTimeout(() => {
      void persist();
    }, 700);
    return () => clearTimeout(handle);
  }, [persist]);

  if (definitions.length === 0) return null;

  return (
    <section className="space-y-3" data-testid="task-custom-fields">
      <div className="flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Custom fields
        </h3>
        {saving && (
          <span className="text-xs text-muted-foreground">Saving…</span>
        )}
      </div>

      {violations.__collection && (
        <Alert variant="destructive">
          <AlertDescription>{violations.__collection}</AlertDescription>
        </Alert>
      )}

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
              <Badge variant="outline" className="shrink-0 text-[10px] uppercase">
                {subtypeLabelFor(def.kind, def.subtype)}
              </Badge>
            </div>
            <CustomFieldValueEditor
              definition={def}
              value={working[def["@id"]] ?? null}
              onChange={(next) => handleChange(def["@id"], next)}
              error={violationFor(violations, def["@id"])}
              disabled={disabled}
              projectIri={projectIri}
              spaceIri={spaceIri}
              users={users}
            />
          </div>
        ))}
      </div>
    </section>
  );
};

export default CustomFieldValueList;
