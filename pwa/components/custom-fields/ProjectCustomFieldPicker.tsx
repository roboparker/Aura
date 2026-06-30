import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Plus, Settings2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import { visibilitySurfaces, type CustomFieldDefinition } from "./types";

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
  /** Per-project visibility ('list'|'board'|'both') for attached fields, keyed by definition IRI. */
  projectVisibility: Record<string, string>;
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

  const patchAttached = async (iris: string[]): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${projectIri}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ customFieldDefinitions: iris }),
    });
    if (!res.ok) throw new Error("Failed to update fields.");
  };

  // The List / Board / Calendar toggles drive both attachment and visibility:
  // a field is on the project when any is on. Turning the last one off detaches
  // it; turning one on (re)attaches and sets the per-project surface set.
  const updateField = async (
    iri: string,
    nextList: boolean,
    nextBoard: boolean,
    nextCalendar: boolean,
  ) => {
    const wasAttached = attachedIris.includes(iri);
    const projectId = projectIri.split("/").pop();
    const defId = iri.split("/").pop();
    setBusy(iri);
    try {
      if (!nextList && !nextBoard && !nextCalendar) {
        if (wasAttached) {
          await patchAttached(attachedIris.filter((i) => i !== iri));
        }
      } else {
        const surfaces = [
          nextList ? "list" : null,
          nextBoard ? "board" : null,
          nextCalendar ? "calendar" : null,
        ].filter(Boolean);
        if (!wasAttached) {
          await patchAttached([...attachedIris, iri]);
        }
        const res = await fetch(
          `${ENTRYPOINT}/projects/${encodeURIComponent(projectId ?? "")}/custom_field_definitions/${encodeURIComponent(defId ?? "")}/visibility`,
          {
            method: "PUT",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ visibility: surfaces.join(",") }),
          },
        );
        if (!res.ok) throw new Error("Failed to update visibility.");
      }
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
            Toggle where each of the space&apos;s fields shows on this
            project — the task list, the board, and/or the calendar (all off =
            not on this project). Define and order fields in{" "}
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
            const surfaces = checked
              ? visibilitySurfaces(projectVisibility[def["@id"]] ?? "both")
              : [];
            const showList = surfaces.includes("list");
            const showBoard = surfaces.includes("board");
            const showCalendar = surfaces.includes("calendar");
            const isBusy = busy === def["@id"];
            return (
              <li
                key={def["@id"]}
                className="flex items-center gap-3 px-3 py-2 text-sm"
              >
                <span className={cn("min-w-0 flex-1 truncate", !checked && "text-muted-foreground")}>
                  {def.name}
                </span>
                <span className="shrink-0 text-xs text-muted-foreground">
                  {KIND_LABEL[def.kind] ?? def.kind}
                </span>
                <label className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                  <Switch
                    checked={showList}
                    disabled={isBusy}
                    onCheckedChange={(v) =>
                      void updateField(def["@id"], v, showBoard, showCalendar)
                    }
                    aria-label={`Show ${def.name} on the task list`}
                  />
                  List
                </label>
                <label className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                  <Switch
                    checked={showBoard}
                    disabled={isBusy}
                    onCheckedChange={(v) =>
                      void updateField(def["@id"], showList, v, showCalendar)
                    }
                    aria-label={`Show ${def.name} on the board`}
                  />
                  Board
                </label>
                <label className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground">
                  <Switch
                    checked={showCalendar}
                    disabled={isBusy}
                    onCheckedChange={(v) =>
                      void updateField(def["@id"], showList, showBoard, v)
                    }
                    aria-label={`Show ${def.name} on the calendar`}
                  />
                  Calendar
                </label>
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
