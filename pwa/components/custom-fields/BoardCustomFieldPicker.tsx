import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Settings2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import {
  isGlobalDefinition,
  visibilitySurfaces,
  type CustomFieldDefinition,
} from "./types";

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  /** Space that owns the space fields (#custom-fields-space). */
  spaceIri: string;
  /** This board's IRI (`/boards/{id}`) — the PATCH target. */
  boardIri: string;
  /** IRIs of the SPACE fields currently shown on this board. */
  attachedIris: string[];
  /** IRIs of the GLOBAL fields currently shown on this board. */
  attachedGlobalIris: string[];
  /** Per-board visibility for attached fields, keyed by definition IRI. */
  boardVisibility: Record<string, string>;
  /** Space admins can edit space definitions inline (creation lives in space settings). */
  isSpaceAdmin: boolean;
  /** Open the field editor for an existing SPACE definition. */
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

/** Endpoint segment for a field source's per-board routes. */
const routeBaseFor = (global: boolean): string =>
  global ? "global_custom_field_definitions" : "custom_field_definitions";

/** The board M2M key for a field source. */
const attachKeyFor = (global: boolean): string =>
  global ? "globalCustomFieldDefinitions" : "customFieldDefinitions";

/**
 * Per-board field selector: fields are defined at the space level
 * (#custom-fields-space) or instance-wide by admins (#global-custom-fields);
 * each board ticks the ones it shows on its tasks. Toggling PATCHes the
 * board's matching M2M (`customFieldDefinitions` / `globalCustomFieldDefinitions`)
 * — a board's effective field set is the union. Space fields can be edited
 * inline by space admins; global fields are managed by platform admins on the
 * admin page, so they're shown read-only here.
 */
const BoardCustomFieldPicker = ({
  spaceIri,
  boardIri,
  attachedIris,
  attachedGlobalIris,
  boardVisibility,
  isSpaceAdmin,
  onEdit,
  onChanged,
}: Props) => {
  const [spaceFields, setSpaceFields] = useState<CustomFieldDefinition[]>([]);
  const [globalFields, setGlobalFields] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    const sortFields = (list: CustomFieldDefinition[]): CustomFieldDefinition[] =>
      [...list].sort(
        (a, b) => a.position - b.position || a.name.localeCompare(b.name),
      );
    try {
      const [spaceRes, globalRes] = await Promise.all([
        fetch(
          `${ENTRYPOINT}/custom_field_definitions?space=${encodeURIComponent(spaceIri)}`,
          { credentials: "include", headers: { Accept: "application/ld+json" } },
        ),
        fetch(`${ENTRYPOINT}/global_custom_field_definitions`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        }),
      ]);
      if (!spaceRes.ok) throw new Error("Failed to load fields.");
      const spaceData: Collection<CustomFieldDefinition> = await spaceRes.json();
      setSpaceFields(sortFields(spaceData.member ?? spaceData["hydra:member"] ?? []));
      if (globalRes.ok) {
        const globalData: Collection<CustomFieldDefinition> = await globalRes.json();
        setGlobalFields(
          sortFields(globalData.member ?? globalData["hydra:member"] ?? []),
        );
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load fields.");
    } finally {
      setIsLoading(false);
    }
  }, [spaceIri]);

  useEffect(() => {
    void load();
  }, [load]);

  const patchAttached = async (global: boolean, iris: string[]): Promise<void> => {
    const res = await fetch(`${ENTRYPOINT}${boardIri}`, {
      method: "PATCH",
      credentials: "include",
      headers: { "Content-Type": "application/merge-patch+json" },
      body: JSON.stringify({ [attachKeyFor(global)]: iris }),
    });
    if (!res.ok) throw new Error("Failed to update fields.");
  };

  // The List / Board / Calendar toggles drive both attachment and visibility:
  // a field is on the board when any is on. Turning the last one off detaches
  // it; turning one on (re)attaches and sets the per-board surface set.
  const updateField = async (
    iri: string,
    global: boolean,
    nextList: boolean,
    nextBoard: boolean,
    nextCalendar: boolean,
  ) => {
    const attached = global ? attachedGlobalIris : attachedIris;
    const wasAttached = attached.includes(iri);
    const boardId = boardIri.split("/").pop();
    const defId = iri.split("/").pop();
    setBusy(iri);
    try {
      if (!nextList && !nextBoard && !nextCalendar) {
        if (wasAttached) {
          await patchAttached(global, attached.filter((i) => i !== iri));
        }
      } else {
        const surfaces = [
          nextList ? "list" : null,
          nextBoard ? "board" : null,
          nextCalendar ? "calendar" : null,
        ].filter(Boolean);
        if (!wasAttached) {
          await patchAttached(global, [...attached, iri]);
        }
        const res = await fetch(
          `${ENTRYPOINT}/boards/${encodeURIComponent(boardId ?? "")}/${routeBaseFor(global)}/${encodeURIComponent(defId ?? "")}/visibility`,
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

  const renderRow = (def: CustomFieldDefinition) => {
    const global = isGlobalDefinition(def);
    const attached = global ? attachedGlobalIris : attachedIris;
    const checked = attached.includes(def["@id"]);
    const surfaces = checked
      ? visibilitySurfaces(boardVisibility[def["@id"]] ?? "both")
      : [];
    const showList = surfaces.includes("list");
    const showBoard = surfaces.includes("board");
    const showCalendar = surfaces.includes("calendar");
    const isBusy = busy === def["@id"];
    return (
      <li key={def["@id"]} className="flex items-center gap-3 px-3 py-2 text-sm">
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
              void updateField(def["@id"], global, v, showBoard, showCalendar)
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
              void updateField(def["@id"], global, showList, v, showCalendar)
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
              void updateField(def["@id"], global, showList, showBoard, v)
            }
            aria-label={`Show ${def.name} on the calendar`}
          />
          Calendar
        </label>
        {isSpaceAdmin && !global ? (
          <button
            type="button"
            onClick={() => onEdit(def)}
            aria-label={`Edit ${def.name}`}
            className="shrink-0 rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            <Settings2 className="h-3.5 w-3.5" />
          </button>
        ) : (
          <span className="w-[1.625rem] shrink-0" />
        )}
      </li>
    );
  };

  return (
    <div className="space-y-4" data-testid="board-custom-field-picker">
      <div className="space-y-1">
        <h3 className="text-sm font-medium">Custom fields</h3>
        <p className="text-xs text-muted-foreground">
          Toggle where each field shows on this board — the task list, the
          board, and/or the calendar (all off = not on this board). Space
          fields are defined in{" "}
          <Link href="/custom-fields" className="text-primary hover:underline">
            space settings
          </Link>
          ; global fields are managed by admins.
        </p>
      </div>

      {error && <p className="text-sm text-destructive">{error}</p>}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading fields…</p>
      ) : (
        <div className="space-y-4">
          <section className="space-y-1.5">
            <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              Space fields
            </h4>
            {spaceFields.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                This space has no custom fields yet. Define them in{" "}
                <Link href="/custom-fields" className="text-primary hover:underline">
                  space settings
                </Link>
                .
              </p>
            ) : (
              <ul className="divide-y rounded-md border">
                {spaceFields.map(renderRow)}
              </ul>
            )}
          </section>

          {globalFields.length > 0 && (
            <section className="space-y-1.5">
              <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Global fields
              </h4>
              <ul className="divide-y rounded-md border">
                {globalFields.map(renderRow)}
              </ul>
            </section>
          )}
        </div>
      )}
    </div>
  );
};

export default BoardCustomFieldPicker;
