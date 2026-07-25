/**
 * Mirror of the API's kind/subtype taxonomy (#227). Kept in lock-step
 * with `App\CustomField\CustomFieldKind` and the strategy registry —
 * adding a new server-side strategy needs a matching entry here AND
 * an editor descriptor in `kind-editors.tsx`.
 */
export type CustomFieldKind =
  | "boolean"
  | "text"
  | "numeric"
  | "date"
  | "select"
  | "reference";

export type CustomFieldSubtype =
  // boolean
  | "boolean"
  // text
  | "text"
  | "rich_text"
  | "url"
  // numeric
  | "int"
  | "float"
  | "money"
  // date
  | "date"
  | "time"
  | "datetime"
  // select
  | "single"
  | "multi"
  // reference
  | "user"
  | "task"
  | "board"
  | "page";

export type FooterKind = "sum" | "avg" | "min" | "max" | "count" | "breakdown";

/** One entry of a select field's per-option `breakdown` footer value. */
export interface FooterBreakdownEntry {
  key: string;
  label: string;
  count: number;
}

export interface SelectOption {
  key: string;
  label: string;
  color?: string;
}

/**
 * Per-kind config payload. The server enforces shape; the PWA only
 * needs to know which keys to render an editor for. Strategies that
 * don't engage a key just omit it from the persisted JSON.
 */
export interface CustomFieldConfig {
  multi?: boolean;
  // text.*
  minLength?: number;
  maxLength?: number;
  pattern?: string;
  // numeric.{int,float} use numeric bounds; date.* use ISO-string bounds
  // in the subtype's wire format. Both ride the same keys.
  min?: number | string;
  max?: number | string;
  currency?: string;
  // select.{single,multi}
  options?: SelectOption[];
}

export interface FooterDescriptor {
  kind: FooterKind;
  label?: string;
}

/** The independent surfaces a field's value can show on (task drawer always shows all). */
export type CustomFieldSurface = "list" | "board" | "calendar";
export const CUSTOM_FIELD_SURFACES: CustomFieldSurface[] = ["list", "board", "calendar"];

/**
 * Stored visibility is a comma-joined SET of surfaces, with legacy single
 * values still accepted ("both" = list + board). Kept as a string on the wire.
 */
export type CustomFieldVisibility = string;

/** Parse a stored visibility value into its surfaces (handles legacy "both"). */
export const visibilitySurfaces = (
  visibility: string | null | undefined,
): CustomFieldSurface[] => {
  if (!visibility || visibility === "both") return ["list", "board"];
  return visibility
    .split(",")
    .map((s) => s.trim())
    .filter((s): s is CustomFieldSurface =>
      (CUSTOM_FIELD_SURFACES as string[]).includes(s),
    );
};

/** Whether a stored visibility includes a given surface. */
export const showsOnSurface = (
  visibility: string | null | undefined,
  surface: CustomFieldSurface,
): boolean => visibilitySurfaces(visibility).includes(surface);

export interface CustomFieldDefinition {
  "@id": string;
  id: string;
  name: string;
  kind: CustomFieldKind;
  subtype: CustomFieldSubtype;
  config: CustomFieldConfig;
  footer: FooterDescriptor | null;
  nullable: boolean;
  position: number;
  visibility: CustomFieldVisibility;
  /**
   * Stable handle for a system global field the app provisions (e.g. the
   * Timeline "Start date" field, `timeline_start`). Null/absent on ordinary
   * fields; present only on global definitions.
   */
  systemKey?: string | null;
}

/** systemKey of the canonical global field that drives the board Timeline. */
export const TIMELINE_START_SYSTEM_KEY = "timeline_start";

/**
 * Whether a definition (or bare IRI) is an instance-wide GLOBAL field rather
 * than a space-owned one (#global-custom-fields). Global fields live at
 * `/global_custom_field_definitions/...`; a task value keys them on
 * `globalDefinition` instead of `definition`, and per-board attach /
 * visibility use the parallel `global_custom_field_definitions` routes.
 */
export const isGlobalDefinition = (
  defOrIri: string | { "@id": string },
): boolean => {
  const iri = typeof defOrIri === "string" ? defOrIri : defOrIri["@id"];
  return iri.includes("/global_custom_field_definitions/");
};

export interface FooterRow {
  definition: string;
  name: string;
  kind: FooterKind;
  label: string | null;
  value: unknown;
}

export interface FooterResponse {
  footers: FooterRow[];
}

/** Per-definition fill stat from `GET /boards/{id}/custom_field_stats`. */
export interface FieldFillStat {
  definition: string;
  filled: number;
}

export interface FieldStatsResponse {
  total: number;
  stats: FieldFillStat[];
}

/** Per-option usage from `GET /custom_field_definitions/{id}/option_stats`. */
export interface OptionStat {
  key: string;
  label: string;
  count: number;
}

export interface OptionStatsResponse {
  options: OptionStat[];
}

/** One row from the board-scoped custom-field change log. */
export interface ActivityRow {
  id: number;
  action: string;
  loggedAt: string | null;
  objectClass: string;
  objectId: string;
  version: number;
  actor: string | null;
  data: Record<string, unknown>;
}

export interface ActivityActor {
  "@id": string;
  id: string;
  email: string;
  givenName: string | null;
  familyName: string | null;
  personalizedColor: string | null;
  avatarUrls?: Record<string, string> | null;
}

export interface ActivityResponse {
  items: ActivityRow[];
  totalItems: number;
  page: number;
  itemsPerPage: number;
  actors: Record<string, ActivityActor>;
}
