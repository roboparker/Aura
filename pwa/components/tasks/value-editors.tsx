import { useEffect, useMemo, useState } from "react";
import { Plus, X } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Combobox,
  ComboboxChip,
  ComboboxChips,
  ComboboxChipsInput,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxValue,
} from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
import type { Elevation } from "@/lib/elevation";
import {
  InputGroup,
  InputGroupAddon,
  InputGroupInput,
  InputGroupText,
} from "@/components/ui/input-group";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
import { currencyFractionDigits } from "@/lib/currencies";
import { cn } from "@/lib/utils";
import type {
  CustomFieldDefinition,
  SelectOption,
} from "@/components/custom-fields/types";

/**
 * Per-kind VALUE editors for `Task.customFieldValues` — the consumer side
 * of #227 (the admin CONFIG editors live in
 * `pwa/components/custom-fields/kind-editors.tsx`). Each editor renders the
 * input for one (kind, subtype[, multi]) and writes the value back in the
 * exact JSON shape the strategy expects:
 *
 *   boolean            -> bool | null
 *   text.* (single)    -> string
 *   text.* (multi)     -> string[]
 *   numeric.int/float  -> number
 *   numeric.money      -> { amount: <int minor units>, currency: <ISO> }
 *   date.date          -> "Y-m-d"
 *   date.time          -> "H:i"
 *   date.datetime      -> ISO-8601
 *   date.* (multi)     -> string[]
 *   select.single      -> key string
 *   select.multi       -> key[]
 *   reference.* single -> IRI string
 *   reference.* multi  -> IRI[]
 *
 * `null` means "no value" for nullable fields. Inline 422s are passed in
 * via `error` and rendered under the input.
 */
export interface ValueEditorProps {
  definition: CustomFieldDefinition;
  value: unknown;
  onChange: (next: unknown) => void;
  error?: string | null;
  disabled?: boolean;
  /** Board the task belongs to (reference task scope). */
  boardIri?: string | null;
  /** Space the board lives in (reference board/page/discussion scope). */
  spaceIri?: string | null;
  /** Space members for reference.user (avoids a fetch). */
  users?: AvatarUser[];
  /** Compact mode (e.g. the new-task form): drop redundant adornments like
   *  the money currency badge. */
  compact?: boolean;
  /** Surface elevation; 0 = inline (flush in a list cell). Defaults to 1. */
  elevation?: Elevation;
}

const isMulti = (definition: CustomFieldDefinition): boolean =>
  Boolean(definition.config.multi) || definition.subtype === "multi";

const asString = (v: unknown): string => (typeof v === "string" ? v : "");
const asStringArray = (v: unknown): string[] =>
  Array.isArray(v) ? v.filter((x): x is string => typeof x === "string") : [];

/** Dispatch to the editor for a definition's (kind, subtype, multi). */
export const CustomFieldValueEditor = (props: ValueEditorProps) => {
  const { definition } = props;
  const { kind, subtype } = definition;

  let control: React.ReactNode;
  if (kind === "boolean") {
    control = <BooleanValue {...props} />;
  } else if (kind === "numeric" && subtype === "money") {
    control = <MoneyValue {...props} />;
  } else if (kind === "numeric") {
    control = <NumericValue {...props} />;
  } else if (kind === "text") {
    control = <TextValue {...props} />;
  } else if (kind === "date") {
    control = <DateValue {...props} />;
  } else if (kind === "select") {
    control = <SelectValue {...props} />;
  } else if (kind === "reference") {
    control = <ReferenceValue {...props} />;
  } else {
    control = null;
  }

  return (
    <div className="space-y-1.5" data-testid="custom-field-value">
      {control}
      {props.error && (
        <p className="text-xs text-destructive" data-testid="custom-field-value-error">
          {props.error}
        </p>
      )}
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* boolean                                                            */
/* ------------------------------------------------------------------ */

const BooleanValue = ({ value, onChange, disabled }: ValueEditorProps) => {
  const checked = value === true;
  return (
    <div className="flex items-center gap-2">
      <Switch
        checked={checked}
        onCheckedChange={(next) => onChange(next)}
        disabled={disabled}
        data-testid="custom-field-value-boolean"
      />
      <span className="text-sm text-muted-foreground">
        {checked ? "Yes" : "No"}
      </span>
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* text / url / rich_text (single + multi)                            */
/* ------------------------------------------------------------------ */

const TextValue = (props: ValueEditorProps) => {
  if (isMulti(props.definition)) return <TextMultiValue {...props} />;
  const { definition, value, onChange, disabled, error } = props;
  if (definition.subtype === "rich_text") {
    return (
      <Textarea
        value={asString(value)}
        onChange={(e) => onChange(e.target.value === "" ? null : e.target.value)}
        disabled={disabled}
        aria-invalid={Boolean(error)}
        rows={4}
        elevation={props.elevation}
        data-testid="custom-field-value-text"
      />
    );
  }
  return (
    <Input
      type={definition.subtype === "url" ? "url" : "text"}
      value={asString(value)}
      onChange={(e) => onChange(e.target.value === "" ? null : e.target.value)}
      disabled={disabled}
      aria-invalid={Boolean(error)}
      placeholder={definition.subtype === "url" ? "https://…" : undefined}
      elevation={props.elevation}
      data-testid="custom-field-value-text"
    />
  );
};

/** Free-text chip input for multi text/url — type + Enter to add. */
const TextMultiValue = ({ value, onChange, disabled }: ValueEditorProps) => {
  const items = asStringArray(value);
  const [draft, setDraft] = useState("");
  const commit = () => {
    const trimmed = draft.trim();
    if (trimmed === "" || items.includes(trimmed)) {
      setDraft("");
      return;
    }
    onChange([...items, trimmed]);
    setDraft("");
  };
  return (
    <div className="flex flex-wrap items-center gap-1.5 rounded-md border border-input bg-transparent px-2.5 py-1.5 dark:bg-input/30">
      {items.map((item) => (
        <span
          key={item}
          className="flex items-center gap-1 rounded-sm bg-muted px-1.5 py-0.5 text-xs"
        >
          {item}
          <button
            type="button"
            onClick={() => onChange(items.filter((i) => i !== item))}
            aria-label={`Remove ${item}`}
            className="opacity-50 hover:opacity-100"
          >
            <X className="h-3 w-3" />
          </button>
        </span>
      ))}
      <input
        value={draft}
        disabled={disabled}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault();
            commit();
          } else if (e.key === "Backspace" && draft === "" && items.length > 0) {
            onChange(items.slice(0, -1));
          }
        }}
        onBlur={commit}
        placeholder={items.length === 0 ? "Add value…" : ""}
        className="min-w-16 flex-1 bg-transparent text-sm outline-none"
        data-testid="custom-field-value-text"
      />
    </div>
  );
};

/* ------------------------------------------------------------------ */
/* numeric int/float                                                  */
/* ------------------------------------------------------------------ */

const NumericValue = ({
  definition,
  value,
  onChange,
  disabled,
  error,
  elevation,
}: ValueEditorProps) => (
  <Input
    type="number"
    step={definition.subtype === "int" ? 1 : "any"}
    value={typeof value === "number" ? String(value) : ""}
    onChange={(e) => {
      const raw = e.target.value;
      if (raw === "") {
        onChange(null);
        return;
      }
      const parsed = Number(raw);
      if (Number.isFinite(parsed)) onChange(parsed);
    }}
    disabled={disabled}
    aria-invalid={Boolean(error)}
    elevation={elevation}
    data-testid="custom-field-value-numeric"
  />
);

/* ------------------------------------------------------------------ */
/* money                                                              */
/* ------------------------------------------------------------------ */

interface MoneyShape {
  amount: number;
  currency: string;
}

const isMoneyShape = (v: unknown): v is MoneyShape =>
  typeof v === "object" &&
  v !== null &&
  typeof (v as MoneyShape).amount === "number" &&
  typeof (v as MoneyShape).currency === "string";

const MoneyValue = ({
  definition,
  value,
  onChange,
  disabled,
  error,
  compact,
  elevation,
}: ValueEditorProps) => {
  const currency = definition.config.currency ?? "USD";
  const digits = currencyFractionDigits(currency);
  const factor = 10 ** digits;

  // Stored amount is in minor units; show major in the input.
  const current = isMoneyShape(value) ? value.amount : null;
  const [text, setText] = useState(
    current === null ? "" : (current / factor).toFixed(digits),
  );

  // Keep the input in sync if the underlying value changes externally.
  useEffect(() => {
    setText(current === null ? "" : (current / factor).toFixed(digits));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [current, currency]);

  const commit = (raw: string) => {
    if (raw.trim() === "") {
      onChange(null);
      return;
    }
    const major = Number(raw);
    if (!Number.isFinite(major)) return;
    onChange({ amount: Math.round(major * factor), currency });
  };

  return (
    <div className="flex items-center gap-2">
      <InputGroup
        className={cn(
          "flex-1",
          // Inline (list cell): square, borderless at rest, border on
          // hover/focus to match the other grid editors.
          elevation === 0 &&
            "min-h-11 rounded-none border-transparent bg-transparent shadow-none hover:border-input dark:bg-transparent",
        )}
      >
        <InputGroupAddon>
          <InputGroupText>{currencySymbol(currency)}</InputGroupText>
        </InputGroupAddon>
        <InputGroupInput
          type="number"
          step={digits === 0 ? 1 : 1 / factor}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onBlur={(e) => commit(e.target.value)}
          disabled={disabled}
          aria-invalid={Boolean(error)}
          className="tabular-nums"
          data-testid="custom-field-value-money"
        />
      </InputGroup>
      {!compact && (
        <Badge variant="outline" className="shrink-0 font-mono text-xs">
          {currency}
        </Badge>
      )}
    </div>
  );
};

const currencySymbol = (code: string): string => {
  try {
    const parts = new Intl.NumberFormat(undefined, {
      style: "currency",
      currency: code,
    }).formatToParts(0);
    return parts.find((p) => p.type === "currency")?.value ?? code;
  } catch {
    return code;
  }
};

/* ------------------------------------------------------------------ */
/* date / time / datetime (single + multi)                            */
/* ------------------------------------------------------------------ */

const DateValue = (props: ValueEditorProps) => {
  if (isMulti(props.definition)) {
    const items = asStringArray(props.value);
    return (
      <div className="space-y-2">
        {items.map((item, idx) => (
          <div key={idx} className="flex items-center gap-2">
            <DateSingle
              {...props}
              value={item}
              onChange={(next) => {
                const value = typeof next === "string" ? next : null;
                const copy = [...items];
                if (value === null) copy.splice(idx, 1);
                else copy[idx] = value;
                props.onChange(copy);
              }}
            />
          </div>
        ))}
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={props.disabled}
          onClick={() => props.onChange([...items, ""])}
        >
          <Plus className="mr-1 h-3.5 w-3.5" /> Add date
        </Button>
      </div>
    );
  }
  return <DateSingle {...props} />;
};

const DateSingle = ({
  definition,
  value,
  onChange,
  disabled,
  error,
  elevation,
}: ValueEditorProps) => {
  const [open, setOpen] = useState(false);
  const str = asString(value);

  if (definition.subtype === "time") {
    return (
      <Input
        type="time"
        value={str}
        onChange={(e) => onChange(e.target.value === "" ? null : e.target.value)}
        disabled={disabled}
        aria-invalid={Boolean(error)}
        elevation={elevation}
        data-testid="custom-field-value-date"
      />
    );
  }
  if (definition.subtype === "datetime") {
    // <input type=datetime-local> uses "YYYY-MM-DDTHH:mm" — fine for the wire.
    return (
      <Input
        type="datetime-local"
        value={str.slice(0, 16)}
        onChange={(e) => onChange(e.target.value === "" ? null : e.target.value)}
        disabled={disabled}
        aria-invalid={Boolean(error)}
        elevation={elevation}
        data-testid="custom-field-value-date"
      />
    );
  }

  const selected = str ? new Date(str + "T00:00:00") : undefined;
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          disabled={disabled}
          aria-invalid={Boolean(error)}
          className={cn("w-full justify-start font-normal", !str && "text-muted-foreground")}
          data-testid="custom-field-value-date"
        >
          {str || "Pick a date"}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar
          mode="single"
          selected={selected}
          onSelect={(d) => {
            onChange(d ? toIsoDate(d) : null);
            setOpen(false);
          }}
          autoFocus
        />
        {str && (
          <div className="border-t p-2">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="w-full"
              onClick={() => {
                onChange(null);
                setOpen(false);
              }}
            >
              Clear
            </Button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
};

const toIsoDate = (d: Date): string => {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
};

/* ------------------------------------------------------------------ */
/* select (single + multi)                                            */
/* ------------------------------------------------------------------ */

const SelectValue = ({
  definition,
  value,
  onChange,
  disabled,
  elevation,
}: ValueEditorProps) => {
  const options = useMemo(
    () => definition.config.options ?? [],
    [definition.config.options],
  );
  const byKey = useMemo(
    () => new Map(options.map((o) => [o.key, o])),
    [options],
  );

  if (isMulti(definition)) {
    const keys = asStringArray(value);
    const selected = keys
      .map((k) => byKey.get(k))
      .filter((o): o is SelectOption => Boolean(o));
    return (
      <Combobox<SelectOption, true>
        items={options}
        multiple
        value={selected}
        disabled={disabled}
        onValueChange={(next) => onChange(next.map((o) => o.key))}
        itemToStringLabel={(o) => o.label}
        itemToStringValue={(o) => o.key}
      >
        <ComboboxChips elevation={elevation}>
          <ComboboxValue>
            {selected.map((o) => (
              <ComboboxChip key={o.key} className="gap-1">
                <OptionDot option={o} />
                {o.label}
              </ComboboxChip>
            ))}
          </ComboboxValue>
          <ComboboxChipsInput
            placeholder={selected.length === 0 ? "Select…" : ""}
            data-testid="custom-field-value-select"
          />
        </ComboboxChips>
        <ComboboxContent>
          <ComboboxEmpty>No options.</ComboboxEmpty>
          <ComboboxList>
            {(o: SelectOption) => (
              <ComboboxItem key={o.key} value={o}>
                <OptionDot option={o} />
                <span className="min-w-0 flex-1 truncate">{o.label}</span>
              </ComboboxItem>
            )}
          </ComboboxList>
        </ComboboxContent>
      </Combobox>
    );
  }

  const selected = typeof value === "string" ? byKey.get(value) ?? null : null;
  return (
    <Combobox<SelectOption, false>
      items={options}
      value={selected}
      disabled={disabled}
      onValueChange={(o) => onChange(o ? o.key : null)}
      itemToStringLabel={(o) => o.label}
      itemToStringValue={(o) => o.key}
    >
      <ComboboxInput
        placeholder="Select…"
        showClear
        data-testid="custom-field-value-select"
      />
      <ComboboxContent>
        <ComboboxEmpty>No options.</ComboboxEmpty>
        <ComboboxList>
          {(o: SelectOption) => (
            <ComboboxItem key={o.key} value={o}>
              <OptionDot option={o} />
              <span className="min-w-0 flex-1 truncate">{o.label}</span>
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  );
};

const OptionDot = ({ option }: { option: SelectOption }) => (
  <span
    className="h-2.5 w-2.5 shrink-0 rounded-full ring-1 ring-border"
    style={option.color ? { backgroundColor: option.color } : undefined}
  />
);

/* ------------------------------------------------------------------ */
/* reference (single + multi)                                         */
/* ------------------------------------------------------------------ */

interface RefOption {
  iri: string;
  label: string;
  user?: AvatarUser;
}

const ReferenceValue = (props: ValueEditorProps) => {
  const { definition, value, onChange, disabled, users, boardIri, spaceIri } =
    props;
  const options = useReferenceOptions(
    definition.subtype,
    { users, boardIri, spaceIri },
  );
  const byIri = useMemo(
    () => new Map(options.map((o) => [o.iri, o])),
    [options],
  );
  const labelFor = (iri: string): string => byIri.get(iri)?.label ?? iri;

  if (isMulti(definition)) {
    const iris = asStringArray(value);
    const selected = iris.map((iri) => byIri.get(iri) ?? { iri, label: iri });
    return (
      <Combobox<RefOption, true>
        items={options}
        multiple
        value={selected}
        disabled={disabled}
        onValueChange={(next) => onChange(next.map((o) => o.iri))}
        itemToStringLabel={(o) => o.label}
        itemToStringValue={(o) => o.iri}
      >
        <ComboboxChips elevation={props.elevation}>
          <ComboboxValue>
            {selected.map((o) => (
              <ComboboxChip key={o.iri} className="gap-1">
                {o.user && <UserAvatar user={o.user} size="sm" className="h-4 w-4" />}
                <span className="truncate max-w-[10rem]">{o.label}</span>
              </ComboboxChip>
            ))}
          </ComboboxValue>
          <ComboboxChipsInput
            placeholder={selected.length === 0 ? "Link…" : ""}
            data-testid="custom-field-value-reference"
          />
        </ComboboxChips>
        <ComboboxContent>
          <ComboboxEmpty>Nothing to link.</ComboboxEmpty>
          <ComboboxList>
            {(o: RefOption) => (
              <ComboboxItem key={o.iri} value={o}>
                {o.user && <UserAvatar user={o.user} size="sm" className="h-5 w-5" />}
                <span className="min-w-0 flex-1 truncate">{o.label}</span>
              </ComboboxItem>
            )}
          </ComboboxList>
        </ComboboxContent>
      </Combobox>
    );
  }

  const selected =
    typeof value === "string"
      ? byIri.get(value) ?? { iri: value, label: labelFor(value) }
      : null;
  return (
    <Combobox<RefOption, false>
      items={options}
      value={selected}
      disabled={disabled}
      onValueChange={(o) => onChange(o ? o.iri : null)}
      itemToStringLabel={(o) => o.label}
      itemToStringValue={(o) => o.iri}
    >
      <ComboboxInput
        placeholder="Link…"
        showClear
        data-testid="custom-field-value-reference"
      />
      <ComboboxContent>
        <ComboboxEmpty>Nothing to link.</ComboboxEmpty>
        <ComboboxList>
          {(o: RefOption) => (
            <ComboboxItem key={o.iri} value={o}>
              {o.user && <UserAvatar user={o.user} size="sm" className="h-5 w-5" />}
              <span className="min-w-0 flex-1 truncate">{o.label}</span>
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  );
};

interface RefScope {
  users?: AvatarUser[];
  boardIri?: string | null;
  spaceIri?: string | null;
}

interface CollectionItem {
  "@id": string;
  title?: string;
  name?: string;
}

/**
 * Load the option set for a reference subtype, scoped to match the
 * server-side validation: users from the space members (passed in), tasks
 * from the current board, boards/pages/discussions from the space.
 */
const useReferenceOptions = (
  subtype: string,
  scope: RefScope,
): RefOption[] => {
  const { users, boardIri, spaceIri } = scope;
  const [fetched, setFetched] = useState<RefOption[]>([]);

  const userOptions = useMemo<RefOption[]>(
    () =>
      (users ?? []).map((u) => ({
        iri: (u as AvatarUser & { "@id"?: string })["@id"] ?? "",
        label: displayName(u),
        user: u,
      })),
    [users],
  );

  useEffect(() => {
    if (subtype === "user") return;
    const endpoint = referenceEndpoint(subtype, { boardIri, spaceIri });
    if (!endpoint) {
      setFetched([]);
      return;
    }
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(`${ENTRYPOINT}${endpoint}`, {
          credentials: "include",
          headers: { Accept: "application/ld+json" },
        });
        if (!res.ok) return;
        const data: unknown = await res.json();
        const members = collectionMembers(data);
        if (cancelled) return;
        setFetched(
          members.map((m) => ({
            iri: m["@id"],
            label: m.title ?? m.name ?? m["@id"],
          })),
        );
      } catch {
        /* swallow — picker degrades to free IRIs */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [subtype, boardIri, spaceIri]);

  return subtype === "user" ? userOptions : fetched;
};

const referenceEndpoint = (
  subtype: string,
  scope: { boardIri?: string | null; spaceIri?: string | null },
): string | null => {
  const space = scope.spaceIri
    ? `?space=${encodeURIComponent(scope.spaceIri)}`
    : "";
  switch (subtype) {
    case "task":
      return scope.boardIri
        ? `/tasks?board=${encodeURIComponent(scope.boardIri)}`
        : null;
    case "board":
      return `/boards${space}`;
    case "page":
      return `/pages${space}`;
    case "discussion":
      return `/discussions${space}`;
    default:
      return null;
  }
};

const collectionMembers = (data: unknown): CollectionItem[] => {
  if (data === null || typeof data !== "object") return [];
  const record = data as Record<string, unknown>;
  const list = record.member ?? record["hydra:member"];
  if (!Array.isArray(list)) return [];
  return list.filter(
    (m): m is CollectionItem =>
      m !== null && typeof m === "object" && typeof (m as CollectionItem)["@id"] === "string",
  );
};
