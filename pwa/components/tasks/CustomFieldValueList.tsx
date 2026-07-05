import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Alert, AlertDescription } from "@/components/ui/alert";
import type { AvatarUser } from "@/components/user/UserAvatar";
import { type ViolationMap } from "@/lib/violations";
import {
  isGlobalDefinition,
  type CustomFieldDefinition,
} from "@/components/custom-fields/types";
import CustomFieldValueFields from "./CustomFieldValueFields";

/**
 * One value pair as carried on `Task.customFieldValues`. Polymorphic like the
 * server entity: a value targets EITHER a space-owned field (`definition`) or
 * an instance-wide global field (`globalDefinition`) — exactly one is set.
 */
export interface CustomFieldValuePair {
  definition?: string | null;
  globalDefinition?: string | null;
  value: unknown;
}

/** The definition IRI a value pair belongs to, whichever source it points at. */
export const valuePairDefinitionIri = (pair: CustomFieldValuePair): string =>
  pair.definition ?? pair.globalDefinition ?? "";

/** Build a write pair, keying the right FK by the definition's source. */
export const makeValuePair = (
  def: CustomFieldDefinition,
  value: unknown,
): CustomFieldValuePair =>
  isGlobalDefinition(def["@id"])
    ? { globalDefinition: def["@id"], value }
    : { definition: def["@id"], value };

interface Props {
  definitions: CustomFieldDefinition[];
  values: CustomFieldValuePair[];
  /**
   * Persist the full value array. Returns the raw API-Platform violation
   * map (keyed by propertyPath); `{}` means success. The list maps those
   * paths back onto the field rows it built the array from.
   */
  onSave: (next: CustomFieldValuePair[]) => Promise<ViolationMap>;
  boardIri?: string | null;
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
 * board definition, with inline 422 validation. Holds a working copy of
 * the values and persists the whole array (debounced) so typing feels
 * inline; orphaned values are dropped so the server reaps them.
 */
const CustomFieldValueList = ({
  definitions,
  values,
  onSave,
  boardIri,
  spaceIri,
  users,
  disabled,
}: Props) => {
  // Working copy keyed by definition IRI.
  const initial = useMemo(() => {
    const map: Record<string, unknown> = {};
    for (const v of values) map[valuePairDefinitionIri(v)] = v.value;
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
      payload.push(makeValuePair(def, value));
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

      <CustomFieldValueFields
        definitions={definitions}
        values={working}
        onChange={handleChange}
        violations={violations}
        boardIri={boardIri}
        spaceIri={spaceIri}
        users={users}
        disabled={disabled}
      />
    </section>
  );
};

export default CustomFieldValueList;
