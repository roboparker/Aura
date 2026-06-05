import { ChangeEvent, useState } from "react";
import {
  DndContext,
  DragEndEvent,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
} from "@dnd-kit/core";
import {
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { Check, GripVertical, Plus, X } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import ColorSwatchPicker from "@/components/common/ColorSwatchPicker";
import { AVATAR_PALETTE } from "@/lib/avatarPalette";
import { cn } from "@/lib/utils";
import {
  CURRENCIES,
  currencyFractionDigits,
  findCurrency,
  type CurrencyInfo,
} from "@/lib/currencies";
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
 *     footer pills only offer ones the API will accept),
 *   - whether the kind supports the `config.multi` toggle.
 *
 * Adding a new strategy server-side means adding a row here too — the
 * registry-driven dispatch on the API and the TS map here are the same
 * idea on different sides of the wire.
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
    label: "Boolean",
    subtypes: [{ value: "boolean", label: "Yes / no" }],
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
    label: "Date",
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
      { value: "single", label: "Single-select" },
      { value: "multi", label: "Multi-select" },
    ],
    supportsMulti: false,
    footerKinds: ["count"],
  },
  reference: {
    label: "Reference",
    subtypes: [
      { value: "user", label: "User" },
      { value: "task", label: "Task" },
      { value: "project", label: "Project" },
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

/** Human label for a kind. */
export const kindLabelFor = (kind: CustomFieldKind): string =>
  KIND_DESCRIPTORS[kind]?.label ?? kind;

/** Human label for a (kind, subtype) pair. */
export const subtypeLabelFor = (
  kind: CustomFieldKind,
  subtype: CustomFieldSubtype,
): string =>
  KIND_DESCRIPTORS[kind]?.subtypes.find((s) => s.value === subtype)?.label ??
  subtype;

/**
 * Target-type cards for the reference editor. Selecting a card changes
 * the field's subtype (the strategy registry key).
 */
const REFERENCE_TARGETS: Array<{
  value: CustomFieldSubtype;
  label: string;
  description: string;
}> = [
  { value: "user", label: "User", description: "Pick a member of this space." },
  { value: "task", label: "Task", description: "Link to a task in this space." },
  { value: "project", label: "Project", description: "Link to a project in this space." },
  { value: "page", label: "Page", description: "Link to a page in this space." },
  { value: "discussion", label: "Discussion", description: "Link to a discussion in this space." },
];

/**
 * Default config for a freshly picked (kind, subtype) pair. Mirrors the
 * strategy defaults — text gets `{multi: false}`, money a default
 * currency, select a seeded option row, etc.
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
    // Seed one option row so an editable input appears as soon as the
    // kind is picked.
    return {
      multi: subtype === "multi",
      options: [{ key: "", label: "", color: AVATAR_PALETTE[0] }],
    };
  }
  return { multi: false };
};

/**
 * Defensive fallback: if a definition lands with a subtype the
 * catalogue doesn't know about, default to the kind's first advertised
 * subtype so the editor still renders.
 */
export const fallbackSubtypeFor = (kind: CustomFieldKind): CustomFieldSubtype =>
  KIND_DESCRIPTORS[kind].subtypes[0].value;

interface ConfigEditorProps {
  kind: CustomFieldKind;
  subtype: CustomFieldSubtype;
  config: CustomFieldConfig;
  onChange: (next: CustomFieldConfig) => void;
  /** Reference target cards change the subtype, not just config. */
  onSubtypeChange?: (next: CustomFieldSubtype) => void;
  /** Active space name, for the reference scope note. */
  spaceName?: string;
  /** Per-option usage counts (by key), shown on select fields in edit mode. */
  optionStats?: Record<string, number>;
}

/**
 * Renders the kind-specific config rows. The shared rows (multi + required
 * toggles, footer pills) live in the composer sheet; this owns the
 * per-kind/subtype knobs.
 */
export const CustomFieldConfigEditor = ({
  kind,
  subtype,
  config,
  onChange,
  onSubtypeChange,
  spaceName,
  optionStats,
}: ConfigEditorProps) => {
  const update = (patch: Partial<CustomFieldConfig>) =>
    onChange({ ...config, ...patch });

  if (kind === "text") {
    return <TextEditor subtype={subtype} config={config} update={update} />;
  }

  if (kind === "numeric" && subtype === "money") {
    return <MoneyEditor config={config} update={update} />;
  }

  if (kind === "numeric") {
    return (
      <div className="grid grid-cols-2 gap-3">
        <NumberField
          id="cf-num-min"
          label="Min"
          value={typeof config.min === "number" ? config.min : undefined}
          onChange={(v) => update({ min: v })}
        />
        <NumberField
          id="cf-num-max"
          label="Max"
          value={typeof config.max === "number" ? config.max : undefined}
          onChange={(v) => update({ max: v })}
        />
      </div>
    );
  }

  if (kind === "date") {
    return <DateEditor subtype={subtype} config={config} update={update} />;
  }

  if (kind === "select") {
    return (
      <SelectEditor config={config} update={update} optionStats={optionStats} />
    );
  }

  if (kind === "reference") {
    return (
      <ReferenceEditor
        subtype={subtype}
        spaceName={spaceName}
        onSubtypeChange={onSubtypeChange}
      />
    );
  }

  // boolean: no extra config rows.
  return null;
};

/* ------------------------------------------------------------------ */
/* Text / URL                                                          */
/* ------------------------------------------------------------------ */

interface SubEditorProps {
  config: CustomFieldConfig;
  update: (patch: Partial<CustomFieldConfig>) => void;
}

const TextEditor = ({
  subtype,
  config,
  update,
}: SubEditorProps & { subtype: CustomFieldSubtype }) => (
  <div className="space-y-3">
    <div className="grid grid-cols-2 gap-3">
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
      <Label htmlFor="cf-text-pattern">
        Pattern{" "}
        <span className="text-muted-foreground text-xs">(regex, optional)</span>
      </Label>
      <Input
        id="cf-text-pattern"
        value={config.pattern ?? ""}
        onChange={(e) =>
          update({ pattern: e.target.value === "" ? undefined : e.target.value })
        }
        placeholder={subtype === "url" ? "^https?://" : "^[A-Z]{2,}$"}
        data-testid="custom-field-pattern-input"
      />
    </div>
    {config.pattern && (
      <PatternCheck pattern={config.pattern} isUrl={subtype === "url"} />
    )}
  </div>
);

/**
 * Live regex tester — type sample strings and see which pass the
 * configured pattern. Purely client-side; mirrors how the server runs
 * the value validator without a round trip.
 */
const PatternCheck = ({
  pattern,
  isUrl,
}: {
  pattern: string;
  isUrl: boolean;
}) => {
  const [samples, setSamples] = useState<string[]>(() =>
    isUrl
      ? ["https://acme.com/launch", "acme.com"]
      : ["ABC", "abc"],
  );

  let compiled: RegExp | null = null;
  let compileError = false;
  try {
    compiled = new RegExp(pattern);
  } catch {
    compileError = true;
  }

  return (
    <div className="space-y-1.5 rounded-md border border-input bg-muted/40 p-3">
      <div className="flex items-center justify-between">
        <Label className="text-xs uppercase tracking-wide text-muted-foreground">
          Pattern check
        </Label>
        {compileError && (
          <span className="text-xs text-destructive">Invalid regex</span>
        )}
      </div>
      {samples.map((sample, idx) => {
        const matches = compiled?.test(sample) ?? false;
        return (
          <div key={idx} className="flex items-center gap-2">
            <Input
              value={sample}
              onChange={(e) =>
                setSamples((prev) =>
                  prev.map((s, i) => (i === idx ? e.target.value : s)),
                )
              }
              aria-label={`Sample ${idx + 1}`}
              className="h-8 text-sm"
              data-testid="custom-field-pattern-sample"
            />
            {!compileError &&
              (matches ? (
                <Check className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
              ) : (
                <X className="h-4 w-4 shrink-0 text-destructive" />
              ))}
          </div>
        );
      })}
      <Button
        type="button"
        size="sm"
        variant="ghost"
        className="h-7 px-2 text-xs"
        onClick={() => setSamples((prev) => [...prev, ""])}
      >
        <Plus className="mr-1 h-3 w-3" /> Add sample
      </Button>
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* Money                                                               */
/* ------------------------------------------------------------------ */

const MoneyEditor = ({ config, update }: SubEditorProps) => {
  const code = config.currency ?? "USD";
  const selected = findCurrency(code) ?? null;
  const digits = currencyFractionDigits(code);
  const preview = formatMoneyPreview(1234.5, code, digits);

  return (
    <div className="space-y-3">
      <div className="space-y-1.5">
        <Label>Currency</Label>
        <Combobox<CurrencyInfo, false>
          items={[...CURRENCIES]}
          value={selected}
          onValueChange={(c) => c && update({ currency: c.code })}
          itemToStringLabel={(c) => `${c.code} — ${c.name}`}
          itemToStringValue={(c) => c.code}
        >
          <ComboboxInput
            placeholder="Search currency…"
            data-testid="custom-field-currency-input"
          />
          <ComboboxContent>
            <ComboboxEmpty>No matching currency.</ComboboxEmpty>
            <ComboboxList>
              {(c: CurrencyInfo) => (
                <ComboboxItem key={c.code} value={c}>
                  <span className="font-medium">{c.code}</span>
                  <span className="text-muted-foreground">{c.name}</span>
                </ComboboxItem>
              )}
            </ComboboxList>
          </ComboboxContent>
        </Combobox>
      </div>

      <div className="flex items-center justify-between rounded-md border border-input bg-muted/40 px-3 py-2">
        <div>
          <p className="text-xs uppercase tracking-wide text-muted-foreground">
            Decimal places
          </p>
          <p className="text-sm font-medium">{digits}</p>
        </div>
        <div className="text-right">
          <p className="text-xs uppercase tracking-wide text-muted-foreground">
            Preview
          </p>
          <p className="text-lg font-semibold tabular-nums">{preview}</p>
        </div>
      </div>
      <p className="text-xs text-muted-foreground">
        Precision follows the currency (ISO-4217); amounts are stored in
        minor units.
      </p>
    </div>
  );
};

const formatMoneyPreview = (
  major: number,
  code: string,
  digits: number,
): string => {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: code,
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(major);
  } catch {
    return major.toFixed(digits);
  }
};

/* ------------------------------------------------------------------ */
/* Date / time                                                         */
/* ------------------------------------------------------------------ */

const DateEditor = ({
  subtype,
  config,
  update,
}: SubEditorProps & { subtype: CustomFieldSubtype }) => (
  <div className="space-y-3">
    <div className="grid grid-cols-2 gap-3">
      <DateBoundField
        id="cf-date-min"
        label="Earliest"
        subtype={subtype}
        value={typeof config.min === "string" ? config.min : ""}
        onChange={(v) => update({ min: v === "" ? undefined : v })}
      />
      <DateBoundField
        id="cf-date-max"
        label="Latest"
        subtype={subtype}
        value={typeof config.max === "string" ? config.max : ""}
        onChange={(v) => update({ max: v === "" ? undefined : v })}
      />
    </div>
    <p className="text-xs text-muted-foreground">
      Values outside this range are rejected at save time. Leave a bound
      empty to disable the check on that side.
    </p>
  </div>
);

const DateBoundField = ({
  id,
  label,
  subtype,
  value,
  onChange,
}: {
  id: string;
  label: string;
  subtype: CustomFieldSubtype;
  value: string;
  onChange: (next: string) => void;
}) => {
  const [open, setOpen] = useState(false);

  if (subtype === "time") {
    return (
      <div className="space-y-1.5">
        <Label htmlFor={id}>{label}</Label>
        <Input
          id={id}
          type="time"
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
    );
  }
  if (subtype === "datetime") {
    return (
      <div className="space-y-1.5">
        <Label htmlFor={id}>{label}</Label>
        <Input
          id={id}
          type="datetime-local"
          value={value}
          onChange={(e) => onChange(e.target.value)}
        />
      </div>
    );
  }

  // date — Calendar in a popover.
  const selected = value ? new Date(value + "T00:00:00") : undefined;
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id={id}
            type="button"
            variant="outline"
            className={cn(
              "w-full justify-start font-normal",
              !value && "text-muted-foreground",
            )}
          >
            {value || "Any"}
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0" align="start">
          <Calendar
            mode="single"
            selected={selected}
            onSelect={(d) => {
              onChange(d ? toIsoDate(d) : "");
              setOpen(false);
            }}
            autoFocus
          />
          {value && (
            <div className="border-t p-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="w-full"
                onClick={() => {
                  onChange("");
                  setOpen(false);
                }}
              >
                Clear
              </Button>
            </div>
          )}
        </PopoverContent>
      </Popover>
    </div>
  );
};

const toIsoDate = (d: Date): string => {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
};

/* ------------------------------------------------------------------ */
/* Select                                                              */
/* ------------------------------------------------------------------ */

const SelectEditor = ({
  config,
  update,
  optionStats,
}: SubEditorProps & { optionStats?: Record<string, number> }) => {
  const options = config.options ?? [];
  const sensors = useSensors(useSensor(PointerSensor));

  const setOption = (idx: number, patch: Partial<SelectOption>) =>
    update({
      options: options.map((opt, i) => (i === idx ? { ...opt, ...patch } : opt)),
    });
  const addOption = () =>
    update({
      options: [
        ...options,
        { key: "", label: "", color: AVATAR_PALETTE[options.length % AVATAR_PALETTE.length] },
      ],
    });
  const removeOption = (idx: number) =>
    update({ options: options.filter((_, i) => i !== idx) });

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const from = Number(active.id);
    const to = Number(over.id);
    if (Number.isNaN(from) || Number.isNaN(to)) return;
    const next = [...options];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    update({ options: next });
  };

  return (
    <div className="space-y-2" data-testid="custom-field-options">
      <Label>Options</Label>
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragEnd={handleDragEnd}
      >
        <SortableContext
          items={options.map((_, i) => String(i))}
          strategy={verticalListSortingStrategy}
        >
          <div className="space-y-2">
            {options.map((opt, idx) => (
              <SortableOptionRow
                key={idx}
                index={idx}
                option={opt}
                usage={opt.key ? optionStats?.[opt.key] : undefined}
                onLabelChange={(label) =>
                  setOption(idx, {
                    label,
                    key:
                      opt.key === "" || opt.key === slugify(opt.label)
                        ? slugify(label)
                        : opt.key,
                  })
                }
                onColorChange={(color) => setOption(idx, { color })}
                onRemove={() => removeOption(idx)}
              />
            ))}
          </div>
        </SortableContext>
      </DndContext>
      <Button
        type="button"
        size="sm"
        variant="outline"
        onClick={addOption}
        data-testid="custom-field-option-add"
      >
        <Plus className="mr-1 h-3.5 w-3.5" /> Add option
      </Button>
    </div>
  );
};

const slugify = (label: string): string =>
  label
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");

const SortableOptionRow = ({
  index,
  option,
  usage,
  onLabelChange,
  onColorChange,
  onRemove,
}: {
  index: number;
  option: SelectOption;
  usage?: number;
  onLabelChange: (label: string) => void;
  onColorChange: (color: string) => void;
  onRemove: () => void;
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: String(index) });
  const [colorOpen, setColorOpen] = useState(false);
  const color = option.color ?? AVATAR_PALETTE[0];

  return (
    <div
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        "flex items-center gap-2 rounded-md",
        isDragging && "opacity-60",
      )}
    >
      <button
        type="button"
        className="cursor-grab text-muted-foreground hover:text-foreground"
        aria-label={`Reorder option ${index + 1}`}
        {...attributes}
        {...listeners}
      >
        <GripVertical className="h-4 w-4" />
      </button>
      <Popover open={colorOpen} onOpenChange={setColorOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label={`Color for option ${index + 1}`}
            className="h-5 w-5 shrink-0 rounded-full ring-1 ring-border"
            style={{ backgroundColor: color }}
          />
        </PopoverTrigger>
        <PopoverContent className="w-auto p-2" align="start">
          <ColorSwatchPicker
            value={color}
            onChange={(c) => {
              onColorChange(c);
              setColorOpen(false);
            }}
            ariaLabel={`Option ${index + 1} color`}
          />
        </PopoverContent>
      </Popover>
      <Input
        value={option.label}
        onChange={(e) => onLabelChange(e.target.value)}
        placeholder={`Option ${index + 1}`}
        aria-label={`Option ${index + 1}`}
        data-testid="custom-field-option-input"
      />
      {typeof usage === "number" && (
        <Badge variant="secondary" className="shrink-0 tabular-nums">
          {usage}
        </Badge>
      )}
      <Button
        type="button"
        size="sm"
        variant="ghost"
        onClick={onRemove}
        aria-label={`Remove option ${index + 1}`}
      >
        <X className="h-4 w-4" />
      </Button>
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* Reference                                                           */
/* ------------------------------------------------------------------ */

const ReferenceEditor = ({
  subtype,
  spaceName,
  onSubtypeChange,
}: {
  subtype: CustomFieldSubtype;
  spaceName?: string;
  onSubtypeChange?: (next: CustomFieldSubtype) => void;
}) => (
  <div className="space-y-2" data-testid="custom-field-reference-targets">
    <Label>Target type</Label>
    <div className="space-y-2">
      {REFERENCE_TARGETS.map((target) => {
        const active = subtype === target.value;
        return (
          <button
            key={target.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onSubtypeChange?.(target.value)}
            className={cn(
              "flex w-full items-start justify-between gap-3 rounded-md border p-3 text-left transition",
              active
                ? "border-primary ring-1 ring-primary bg-primary/5"
                : "border-input hover:border-muted-foreground/40",
            )}
            data-testid={`custom-field-reference-${target.value}`}
          >
            <span>
              <span className="block text-sm font-medium">{target.label}</span>
              <span className="block text-xs text-muted-foreground">
                {target.description}
              </span>
            </span>
            <Badge variant="outline" className="shrink-0 font-mono text-xs">
              reference.{target.value}
            </Badge>
          </button>
        );
      })}
    </div>
    <p className="text-xs text-muted-foreground">
      Limited to the current space
      {spaceName ? ` — ${spaceName}` : ""}. Cross-space references aren&apos;t
      allowed.
    </p>
  </div>
);

/* ------------------------------------------------------------------ */
/* Shared number field                                                 */
/* ------------------------------------------------------------------ */

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
