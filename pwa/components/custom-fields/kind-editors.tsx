import { ChangeEvent } from "react";
import { Plus, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import type {
  CustomFieldConfig,
  CustomFieldKind,
  CustomFieldSubtype,
  FooterKind,
  SelectOption,
} from "./types";

/**
 * Per-kind/subtype editor catalogue. Each entry describes:
 *   - which subtypes the kind exposes,
 *   - which footer aggregations the strategy advertises (so the
 *     `<select>` only offers ones the API will accept),
 *   - whether the kind supports the `config.multi` toggle,
 *   - which config fields to render (rendered inline in
 *     `CustomFieldConfigEditor` below).
 *
 * Adding a new strategy server-side means adding a row here too — the
 * registry-driven dispatch on the API and the TS map here are the
 * exact same idea, just on different sides of the wire.
 */
export interface KindDescriptor {
  label: string;
  subtypes: Array<{ value: CustomFieldSubtype; label: string }>;
  supportsMulti: boolean;
  /** Subtypes that opt OUT of multi (rare — money under numeric). */
  noMultiSubtypes?: CustomFieldSubtype[];
  footerKinds: FooterKind[];
}

export const KIND_DESCRIPTORS: Record<CustomFieldKind, KindDescriptor> = {
  boolean: {
    label: "Boolean (checkbox)",
    subtypes: [{ value: "boolean", label: "Checkbox" }],
    supportsMulti: false,
    footerKinds: ["count"],
  },
  text: {
    label: "Text",
    subtypes: [
      { value: "text", label: "Plain text" },
      { value: "rich_text", label: "Markdown" },
      { value: "url", label: "URL" },
    ],
    supportsMulti: true,
    footerKinds: ["count"],
  },
  numeric: {
    label: "Numeric",
    subtypes: [
      { value: "int", label: "Integer" },
      { value: "float", label: "Number" },
      { value: "money", label: "Money" },
    ],
    supportsMulti: true,
    noMultiSubtypes: ["money"],
    footerKinds: ["sum", "avg", "min", "max", "count"],
  },
  date: {
    label: "Date / time",
    subtypes: [
      { value: "date", label: "Date" },
      { value: "time", label: "Time of day" },
      { value: "datetime", label: "Date + time" },
    ],
    supportsMulti: true,
    footerKinds: ["min", "max", "count"],
  },
  select: {
    label: "Select",
    subtypes: [
      { value: "single", label: "Single choice" },
      { value: "multi", label: "Multiple choices" },
    ],
    supportsMulti: false,
    footerKinds: ["count"],
  },
  reference: {
    label: "Reference",
    subtypes: [
      { value: "user", label: "User" },
      { value: "task", label: "Task" },
      { value: "page", label: "Page" },
      { value: "discussion", label: "Discussion" },
    ],
    supportsMulti: true,
    footerKinds: ["count"],
  },
};

export const KIND_ORDER: CustomFieldKind[] = [
  "text",
  "numeric",
  "date",
  "boolean",
  "select",
  "reference",
];

/**
 * Default config for a freshly picked (kind, subtype) pair. Mirrors
 * the strategy defaults — text gets `{multi: false}`, money gets a
 * default currency, etc.
 */
export const defaultConfigFor = (
  kind: CustomFieldKind,
  subtype: CustomFieldSubtype,
): CustomFieldConfig => {
  if (kind === "boolean") return {};
  if (kind === "numeric" && subtype === "money") {
    return { currency: "USD" };
  }
  if (kind === "select") {
    return { multi: subtype === "multi", options: [] };
  }
  return { multi: false };
};

/**
 * Defensive fallback: if a definition lands with a subtype the
 * catalogue doesn't know about (e.g. an upgraded server shipped a
 * new strategy the PWA hasn't seen yet), default to the kind's first
 * advertised subtype so the editor still renders.
 */
export const fallbackSubtypeFor = (kind: CustomFieldKind): CustomFieldSubtype =>
  KIND_DESCRIPTORS[kind].subtypes[0].value;

interface ConfigEditorProps {
  kind: CustomFieldKind;
  subtype: CustomFieldSubtype;
  config: CustomFieldConfig;
  onChange: (next: CustomFieldConfig) => void;
}

/**
 * Renders the kind-specific config rows. The shared rows (multi
 * toggle, footer descriptor) live alongside in the composer; this
 * focuses on the per-kind/subtype knobs.
 */
export const CustomFieldConfigEditor = ({
  kind,
  subtype,
  config,
  onChange,
}: ConfigEditorProps) => {
  const update = (patch: Partial<CustomFieldConfig>) =>
    onChange({ ...config, ...patch });

  if (kind === "text") {
    return (
      <div className="space-y-2">
        <div className="grid grid-cols-2 gap-2">
          <NumberField
            id="cf-text-min"
            label="Min length"
            value={config.minLength}
            onChange={(v) => update({ minLength: v })}
          />
          <NumberField
            id="cf-text-max"
            label="Max length"
            value={config.maxLength}
            onChange={(v) => update({ maxLength: v })}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="cf-text-pattern">Pattern (regex, optional)</Label>
          <Input
            id="cf-text-pattern"
            value={config.pattern ?? ""}
            onChange={(e) =>
              update({ pattern: e.target.value === "" ? undefined : e.target.value })
            }
            data-testid="custom-field-pattern-input"
          />
        </div>
      </div>
    );
  }

  if (kind === "numeric" && subtype !== "money") {
    return (
      <div className="grid grid-cols-2 gap-2">
        <NumberField
          id="cf-num-min"
          label="Min"
          value={config.min}
          onChange={(v) => update({ min: v })}
        />
        <NumberField
          id="cf-num-max"
          label="Max"
          value={config.max}
          onChange={(v) => update({ max: v })}
        />
      </div>
    );
  }

  if (kind === "numeric" && subtype === "money") {
    return (
      <div className="space-y-1.5">
        <Label htmlFor="cf-money-currency">Currency (ISO-4217)</Label>
        <Input
          id="cf-money-currency"
          value={config.currency ?? ""}
          onChange={(e) =>
            update({ currency: e.target.value.toUpperCase().slice(0, 3) })
          }
          maxLength={3}
          required
          data-testid="custom-field-currency-input"
        />
      </div>
    );
  }

  if (kind === "date") {
    return (
      <div className="grid grid-cols-2 gap-2">
        <div className="space-y-1.5">
          <Label htmlFor="cf-date-min">Min</Label>
          <Input
            id="cf-date-min"
            value={config.min !== undefined ? String(config.min) : ""}
            onChange={(e) =>
              update({ min: e.target.value === "" ? undefined : (e.target.value as unknown as number) })
            }
            placeholder={dateBoundPlaceholder(subtype)}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="cf-date-max">Max</Label>
          <Input
            id="cf-date-max"
            value={config.max !== undefined ? String(config.max) : ""}
            onChange={(e) =>
              update({ max: e.target.value === "" ? undefined : (e.target.value as unknown as number) })
            }
            placeholder={dateBoundPlaceholder(subtype)}
          />
        </div>
      </div>
    );
  }

  if (kind === "select") {
    const options = config.options ?? [];
    const setOption = (idx: number, patch: Partial<SelectOption>) =>
      update({
        options: options.map((opt, i) => (i === idx ? { ...opt, ...patch } : opt)),
      });
    const addOption = () =>
      update({ options: [...options, { key: "", label: "" }] });
    const removeOption = (idx: number) =>
      update({ options: options.filter((_, i) => i !== idx) });

    return (
      <div className="space-y-2" data-testid="custom-field-options">
        <Label>Options</Label>
        {options.map((opt, idx) => (
          <div key={idx} className="flex items-center gap-2">
            <Input
              value={opt.label}
              onChange={(e) => {
                const label = e.target.value;
                setOption(idx, {
                  label,
                  key: opt.key === "" || opt.key === opt.label ? label : opt.key,
                });
              }}
              placeholder={`Option ${idx + 1}`}
              aria-label={`Option ${idx + 1}`}
              data-testid="custom-field-option-input"
            />
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={() => removeOption(idx)}
              aria-label={`Remove option ${idx + 1}`}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        ))}
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={addOption}
          data-testid="custom-field-option-add"
        >
          <Plus className="h-3.5 w-3.5 mr-1" /> Add option
        </Button>
      </div>
    );
  }

  // boolean + reference: no extra config rows.
  return null;
};

interface NumberFieldProps {
  id: string;
  label: string;
  value: number | undefined;
  onChange: (next: number | undefined) => void;
}

const NumberField = ({ id, label, value, onChange }: NumberFieldProps) => (
  <div className="space-y-1.5">
    <Label htmlFor={id}>{label}</Label>
    <Input
      id={id}
      type="number"
      value={value !== undefined ? String(value) : ""}
      onChange={(e: ChangeEvent<HTMLInputElement>) => {
        const v = e.target.value;
        if (v === "") {
          onChange(undefined);
          return;
        }
        const parsed = Number(v);
        if (Number.isFinite(parsed)) {
          onChange(parsed);
        }
      }}
    />
  </div>
);

const dateBoundPlaceholder = (subtype: CustomFieldSubtype): string => {
  if (subtype === "time") return "HH:MM";
  if (subtype === "datetime") return "YYYY-MM-DDTHH:MM:SS+00:00";
  return "YYYY-MM-DD";
};
