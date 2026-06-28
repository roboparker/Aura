import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Plus, Settings2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { cn } from "@/lib/utils";
import VisibilityToggles from "./VisibilityToggles";
import type { CustomFieldDefinition, CustomFieldVisibility } from "./types";

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  /** Space that owns the fields (#custom-fields-space). */
  spaceIri: string;
  /** This project's IRI (`/projects/{id}`) — the PATCH target. */
  projectIri: string;
  /** IRIs of the fields currently shown on this project. */
  attachedIris: string[];
  /** Per-project visibility for attached fields, keyed by definition IRI. */
  projectVisibility: Record<string, CustomFieldVisibility>;
  /** Space admins can define new fields / edit definitions. */
  isSpaceAdmin: boolean;
  /** Open the field editor to create a new (space) field. */
  onCreate: () => void;
  /** Open the field editor for an existing definition. */
  onEdit: (def: CustomFieldDefinition) => void;
  /** Re-sync after attach/detach so the task columns update. */
  onChanged: () => void;
}

const KIND_LABEL: Record<string, string> = {
  boolean: "Checkbox",
  text: "Text",
  numeric: "Number",
  date: "Date",
  select: "Select",
  reference: "Reference",
};

/**
 * Per-project field selector (#custom-fields-space): fields are defined at the
 * space level; each project ticks the ones it shows on its tasks. Toggling
 * PATCHes the project's `customFieldDefinitions` M2M. Defining / editing the
 * fields themselves lives in the space-level manager (/custom-fields).
 */
const ProjectCustomFieldPicker = ({
  spaceIri,
  projectIri,
  attachedIris,
  projectVisibility,
  isSpaceAdmin,
  onCreate,
  onEdit,
  onChanged,
}: Props) => {
  const [spaceFields, setSpaceFields] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/custom_field_definitions?space=${encodeURIComponent(spaceIri)}`,
        { credentials: "include", headers: { Accept: "application/ld+json" } },
      );
      if (!res.ok) throw new Error("Failed to load fields.");
      const data: Collection<CustomFieldDefinition> = await res.json();
      const list = data.member ?? data["hydra:member"] ?? [];
      setSpaceFields(
        [...list].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name)),
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load fields.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceIri]);

  useEffect(() => {
    void load();
  }, [load]);

  const setVisibility = async (
    defIri: string,
    visibility: CustomFieldVisibility,
  ) => {
    const projectId = projectIri.split("/").pop();
    const defId = defIri.split("/").pop();
    setBusy(defIri);
    try {
      const res = await fetch(
        `${ENTRYPOINT}/projects/${projectId}/custom_field_definitions/${defId}/visibility`,
        {
          method: "PUT",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ visibility }),
        },
      );
      if (!res.ok) throw new Error("Failed to update visibility.");
      onChanged();
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Failed to update visibility.",
      );
    } finally {
      setBusy(null);
    }
  };

  const toggle = async (iri: string, checked: boolean) => {
    const next = checked
      ? [...attachedIris, iri]
      : attachedIris.filter((i) => i !== iri);
    setBusy(iri);
    try {
      const res = await fetch(`${ENTRYPOINT}${projectIri}`, {
        method: "PATCH",
        credentials: "include",
        headers: { "Content-Type": "application/merge-patch+json" },
        body: JSON.stringify({ customFieldDefinitions: next }),
      });
      if (!res.ok) throw new Error("Failed to update fields.");
      onChanged();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to update fields.");
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-4" data-testid="project-custom-field-picker">
      <div className="flex items-start justify-between gap-3">
        <div className="space-y-1">
          <h3 className="text-sm font-medium">Custom fields</h3>
          <p className="text-xs text-muted-foreground">
            Tick the space&apos;s fields to show them on this project&apos;s
            tasks. Define and order fields in{" "}
            <Link href="/custom-fields" className="text-cyan-700 hover:underline dark:text-cyan-400">
              space settings
            </Link>
            .
          </p>
        </div>
        {isSpaceAdmin && (
          <Button type="button" size="sm" variant="outline" onClick={onCreate}>
            <Plus className="mr-1 h-3.5 w-3.5" /> New field
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading fields…</p>
      ) : spaceFields.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          This space has no custom fields yet.{" "}
          {isSpaceAdmin && (
            <button type="button" onClick={onCreate} className="text-cyan-700 hover:underline dark:text-cyan-400">
              Create one
            </button>
          )}
        </p>
      ) : (
        <ul className="divide-y rounded-md border">
          {spaceFields.map((def) => {
            const checked = attachedIris.includes(def["@id"]);
            return (
              <li
                key={def["@id"]}
                className="flex items-center gap-3 px-3 py-2 text-sm"
              >
                <Checkbox
                  checked={checked}
                  disabled={busy === def["@id"]}
                  onCheckedChange={(v) => void toggle(def["@id"], v === true)}
                  aria-label={`Show ${def.name} on this project`}
                  className="size-4"
                />
                <span className={cn("min-w-0 flex-1 truncate", !checked && "text-muted-foreground")}>
                  {def.name}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {KIND_LABEL[def.kind] ?? def.kind}
                </span>
                {checked && (
                  <VisibilityToggles
                    visibility={projectVisibility[def["@id"]] ?? "both"}
                    editable={!busy}
                    onChange={(v) => void setVisibility(def["@id"], v)}
                  />
                )}
                {isSpaceAdmin && (
                  <button
                    type="button"
                    onClick={() => onEdit(def)}
                    aria-label={`Edit ${def.name}`}
                    className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                  >
                    <Settings2 className="h-3.5 w-3.5" />
                  </button>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
};

export default ProjectCustomFieldPicker;
