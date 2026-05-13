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
  // numeric.{int,float,money}
  min?: number;
  max?: number;
  decimalPlaces?: number;
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
