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
  | "project"
  | "page"
  | "discussion";

export type FooterKind = "sum" | "avg" | "min" | "max" | "count";

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
}

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

/** Per-definition fill stat from `GET /projects/{id}/custom_field_stats`. */
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

/** One row from the project-scoped custom-field change log. */
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
