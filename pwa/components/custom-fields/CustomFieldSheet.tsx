import {
  ChangeEvent,
  FormEvent,
  useEffect,
  useMemo,
  useState,
} from "react";
import { Copy, MoreHorizontal, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import ConfirmDialog from "@/components/common/ConfirmDialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Sheet,
  SheetContent,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Switch } from "@/components/ui/switch";
import { cn } from "@/lib/utils";
import {
  CustomFieldConfigEditor,
  KIND_BADGE,
  KIND_DESCRIPTORS,
  KIND_ORDER,
  configSectionLabel,
  defaultConfigFor,
  fallbackSubtypeFor,
  kindLabelFor,
  subtypeLabelFor,
} from "./kind-editors";
import { CustomFieldValueEditor } from "@/components/tasks/value-editors";
import { fieldHandle } from "./handle";
import type {
  CustomFieldConfig,
  CustomFieldDefinition,
  CustomFieldKind,
  CustomFieldSubtype,
  FooterDescriptor,
  FooterKind,
  OptionStatsResponse,
} from "./types";

/**
 * Right-side drawer for creating or editing one custom field definition.
 * Owns the POST / PATCH / DELETE calls against `/custom_field_definitions`
 * with the {kind, subtype, config, footer, nullable} payload (#227).
 * Per-kind config editors live in `kind-editors.tsx`.
 */
interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Space that owns the field (#custom-fields-space) — set on create. Omitted
   *  for the instance-wide global manager (#global-custom-fields). */
  spaceIri?: string;
  /** Collection endpoint for create (POST). Defaults to the space
   *  `/custom_field_definitions`; the global admin manager passes
   *  `/global_custom_field_definitions`. */
  collectionPath?: string;
  /** Optional project context, used to scope reference-field option lists. */
  projectIri?: string;
  spaceName?: string;
  /** Present when editing an existing field. */
  initial?: CustomFieldDefinition;
  /** Preset the (kind, subtype) for a new field (e.g. opened from the list
   *  view's "add column" type picker). Ignored when editing. */
  initialKind?: CustomFieldKind;
  initialSubtype?: CustomFieldSubtype;
  /** Position to assign a newly created field. */
  initialPosition?: number;
  /** Tasks-with-a-value count, shown in the edit header. */
  valueCount?: number;
  onSaved: (def: CustomFieldDefinition) => void;
  onDeleted?: (def: CustomFieldDefinition) => void;
  /** Duplicate the field being edited (header overflow menu). */
  onDuplicate?: (def: CustomFieldDefinition) => void;
}

const FOOTER_LABELS: Record<FooterKind, string> = {
  count: "Count",
  sum: "Sum",
  avg: "Avg",
  min: "Min",
  max: "Max",
};


const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    "Request failed."
  );
};

/**
 * A representative sample value for the live preview, in each subtype's wire
 * format (matching value-editors.tsx). `reference.*` has no sample IRI, so it
 * previews empty.
 */
const sampleValueFor = (
  kind: CustomFieldKind,
  subtype: CustomFieldSubtype,
  config: CustomFieldConfig,
): unknown => {
  const multi = Boolean(config.multi) || subtype === "multi";
  switch (kind) {
    case "boolean":
      return true;
    case "numeric":
      if (subtype === "money") {
        return { amount: 123450, currency: config.currency ?? "USD" };
      }
      return subtype === "int" ? 42 : 42.5;
    case "text":
      if (subtype === "url") return "https://example.com";
      return multi ? ["Sample"] : "Sample text";
    case "date":
      if (subtype === "time") return "09:00";
      if (subtype === "datetime") return "2026-06-20T09:00";
      return "2026-06-20";
    case "select": {
      const first = config.options?.[0]?.key;
      if (first === undefined) return multi ? [] : null;
      return multi ? [first] : first;
    }
    default:
      return null; // reference — no sample IRI available
  }
};

const CustomFieldSheet = ({
  open,
  onOpenChange,
  spaceIri,
  collectionPath = "/custom_field_definitions",
  projectIri,
  spaceName,
  initial,
  initialKind,
  initialSubtype,
  initialPosition = 0,
  valueCount,
  onSaved,
  onDeleted,
  onDuplicate,
}: Props) => {
  const isEdit = Boolean(initial);

  const [name, setName] = useState("");
  const [kind, setKind] = useState<CustomFieldKind>("text");
  const [subtype, setSubtype] = useState<CustomFieldSubtype>(
    fallbackSubtypeFor("text"),
  );
  const [config, setConfig] = useState<CustomFieldConfig>(
    defaultConfigFor("text", "text"),
  );
  const [nullable, setNullable] = useState(true);
  const [footer, setFooter] = useState<FooterDescriptor | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // When a type change would orphan existing task values, the server
  // replies 409 with the count; we hold it here to drive the confirm
  // dialog, then retry the save with the confirmation flag.
  const [conversionLost, setConversionLost] = useState<number | null>(null);
  const [optionStats, setOptionStats] = useState<Record<string, number>>({});

  // Live preview: build a definition from the current form state and render
  // the real task value editor seeded with a representative sample, so the
  // author sees exactly how the field behaves on a task.
  const previewDefinition = useMemo<CustomFieldDefinition>(
    () => ({
      "@id": "preview",
      id: "preview",
      name: name || "Field",
      kind,
      subtype,
      config,
      footer,
      nullable,
      position: 0,
      visibility: "both",
    }),
    [name, kind, subtype, config, footer, nullable],
  );
  const [previewValue, setPreviewValue] = useState<unknown>(() =>
    sampleValueFor(kind, subtype, config),
  );
  useEffect(() => {
    setPreviewValue(sampleValueFor(kind, subtype, config));
  }, [kind, subtype, config]);

  // Re-seed the form whenever the sheet opens (new vs edit, or a
  // different field).
  useEffect(() => {
    if (!open) return;
    setError(null);
    setOptionStats({});
    if (initial) {
      setName(initial.name);
      setKind(initial.kind);
      setSubtype(initial.subtype);
      setConfig(initial.config);
      setNullable(initial.nullable);
      setFooter(initial.footer);
    } else {
      const k = initialKind ?? "text";
      const s = initialSubtype ?? fallbackSubtypeFor(k);
      setName("");
      setKind(k);
      setSubtype(s);
      setConfig(defaultConfigFor(k, s));
      setNullable(true);
      setFooter(null);
    }
  }, [open, initial, initialKind, initialSubtype]);

  // Pull per-option usage counts for select fields being edited.
  useEffect(() => {
    if (!open || !initial || initial.kind !== "select") return;
    let cancelled = false;
    void (async () => {
      try {
        const res = await fetch(
          `${ENTRYPOINT}${initial["@id"]}/option_stats`,
          { credentials: "include", headers: { Accept: "application/json" } },
        );
        if (!res.ok) return;
        const data: OptionStatsResponse = await res.json();
        if (cancelled) return;
        const map: Record<string, number> = {};
        for (const o of data.options) map[o.key] = o.count;
        setOptionStats(map);
      } catch {
        /* stats are a nicety — ignore failures */
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [open, initial]);

  const descriptor = KIND_DESCRIPTORS[kind];

  const multiSupported = useMemo(() => {
    if (!descriptor.supportsMulti) return false;
    if (descriptor.noMultiSubtypes?.includes(subtype)) return false;
    if (kind === "select") return false; // intrinsic to the subtype
    return true;
  }, [descriptor, subtype, kind]);

  const handleKindChange = (next: CustomFieldKind) => {
    const nextSubtype = KIND_DESCRIPTORS[next].subtypes[0].value;
    setKind(next);
    setSubtype(nextSubtype);
    setConfig(defaultConfigFor(next, nextSubtype));
    setFooter(null);
  };

  const handleSubtypeChange = (next: CustomFieldSubtype) => {
    setSubtype(next);
    if (kind === "select") {
      setConfig((prev) => ({ ...prev, multi: next === "multi" }));
    } else if (kind === "numeric" && next === "money") {
      // Money requires a currency; seed the default so the picker's shown
      // value is actually stored and submit works without touching it.
      setConfig((prev) => ({
        ...prev,
        multi: false,
        currency: prev.currency ?? "USD",
      }));
    } else if (descriptor.noMultiSubtypes?.includes(next)) {
      setConfig((prev) => ({ ...prev, multi: false }));
    }
  };

  const toggleFooter = (fk: FooterKind | null) => {
    if (fk === null) {
      setFooter(null);
      return;
    }
    setFooter((prev) => ({ kind: fk, label: prev?.label }));
  };

  // Performs the actual POST/PATCH. On edit, `confirmConversion` appends
  // the flag that tells the server to delete values that can't be migrated
  // to the new field type.
  const sendSave = (confirmConversion: boolean): Promise<Response> => {
    const body: Record<string, unknown> = {
      name: name.trim(),
      kind,
      subtype,
      config,
      nullable,
      footer,
    };
    if (isEdit && initial) {
      const url = `${ENTRYPOINT}${initial["@id"]}${
        confirmConversion ? "?confirmValueConversion=1" : ""
      }`;
      return fetch(url, {
        method: "PATCH",
        credentials: "include",
        headers: {
          "Content-Type": "application/merge-patch+json",
          Accept: "application/ld+json",
        },
        body: JSON.stringify(body),
      });
    }
    return fetch(`${ENTRYPOINT}${collectionPath}`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/ld+json" },
      body: JSON.stringify({
        ...body,
        ...(spaceIri ? { space: spaceIri } : {}),
        position: initialPosition,
      }),
    });
  };

  const finishSave = async (res: Response) => {
    if (!res.ok) throw new Error(await errorMessage(res));
    const saved: CustomFieldDefinition = await res.json();
    onSaved(saved);
    onOpenChange(false);
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!name.trim()) return;

    setSubmitting(true);
    setError(null);
    try {
      const res = await sendSave(false);
      // The type change would orphan some values — pause and confirm
      // before anything is deleted.
      if (isEdit && res.status === 409) {
        const header = res.headers.get("X-Conversion-Lost-Count");
        const count = header ? Number.parseInt(header, 10) : 0;
        setConversionLost(Number.isFinite(count) && count > 0 ? count : 1);
        return;
      }
      await finishSave(res);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSubmitting(false);
    }
  };

  // Retry the save after the user confirms the lossy conversion. Throws on
  // failure so the ConfirmDialog keeps itself open and surfaces the error.
  const confirmConversion = async () => {
    const res = await sendSave(true);
    await finishSave(res);
    setConversionLost(null);
  };

  const handleDelete = async () => {
    if (!initial || !onDeleted) return;
    if (
      !window.confirm(
        `Delete custom field "${initial.name}"? Existing values on tasks will also be removed.`,
      )
    ) {
      return;
    }
    setDeleting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}${initial["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      onDeleted(initial);
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to delete.");
    } finally {
      setDeleting(false);
    }
  };

  const handle = fieldHandle(name || initial?.name || "");

  return (
    // Non-modal so the in-drawer popups that portal to <body> (the currency
    // Combobox, the date / color Popovers) stay clickable — a modal Radix
    // dialog sets `pointer-events: none` on everything outside its content,
    // which would swallow clicks on those portaled popups. The
    // onInteractOutside guard keeps the drawer open when the user is
    // interacting with one of those popups rather than truly clicking out.
    <>
    <Sheet open={open} onOpenChange={onOpenChange} modal={false}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-md"
        data-testid="custom-field-sheet"
        onInteractOutside={(e) => {
          const target = e.detail.originalEvent.target as HTMLElement | null;
          if (
            target?.closest(
              '[data-slot="combobox-content"],[data-slot="popover-content"],[data-radix-popper-content-wrapper]',
            )
          ) {
            e.preventDefault();
          }
        }}
      >
        <SheetHeader className="border-b px-5 py-4">
          <div className="flex items-center gap-2 pr-9">
            <span className={cn("h-2 w-2 shrink-0 rounded-full", KIND_BADGE[kind].dot)} />
            <SheetTitle className="text-sm font-semibold uppercase tracking-wide">
              {isEdit ? "Edit field" : "New field"}
            </SheetTitle>
            <span className="truncate font-mono text-xs text-muted-foreground">
              {handle}
            </span>
            {isEdit && initial && onDuplicate && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="ml-auto mr-6 h-7 w-7"
                    aria-label="Field actions"
                  >
                    <MoreHorizontal className="h-4 w-4" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem
                    onSelect={() => {
                      onDuplicate(initial);
                      onOpenChange(false);
                    }}
                  >
                    <Copy className="mr-2 h-3.5 w-3.5" /> Duplicate field
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </div>
          {isEdit && typeof valueCount === "number" && (
            <p className="text-xs text-muted-foreground">
              {valueCount} {valueCount === 1 ? "value" : "values"} on tasks
            </p>
          )}
        </SheetHeader>

        <form
          onSubmit={handleSubmit}
          className="flex min-h-0 flex-1 flex-col"
        >
          <div className="flex flex-1 flex-col space-y-4 overflow-y-auto px-5 py-4">
            <div className="space-y-1.5">
              <Label htmlFor="cf-name">Name</Label>
              <Input
                id="cf-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                maxLength={80}
                data-testid="custom-field-name-input"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label htmlFor="cf-kind">Kind</Label>
                <select
                  id="cf-kind"
                  value={kind}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                    handleKindChange(e.target.value as CustomFieldKind)
                  }
                  className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  data-testid="custom-field-kind-input"
                >
                  {KIND_ORDER.map((k) => (
                    <option key={k} value={k}>
                      {KIND_DESCRIPTORS[k].label}
                    </option>
                  ))}
                </select>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="cf-subtype">Subtype</Label>
                <select
                  id="cf-subtype"
                  value={subtype}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) =>
                    handleSubtypeChange(e.target.value as CustomFieldSubtype)
                  }
                  className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  data-testid="custom-field-subtype-input"
                >
                  {descriptor.subtypes.map((s) => (
                    <option key={s.value} value={s.value}>
                      {s.label}
                    </option>
                  ))}
                </select>
              </div>
            </div>

            {kind !== "boolean" && (
              <div className="space-y-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {configSectionLabel(kind, subtype)}
                </p>
                <CustomFieldConfigEditor
                  kind={kind}
                  subtype={subtype}
                  config={config}
                  onChange={setConfig}
                  onSubtypeChange={handleSubtypeChange}
                  spaceName={spaceName}
                  optionStats={optionStats}
                />
              </div>
            )}

            {kind === "select" && (
              <ToggleRow
                id="cf-select-multi"
                label="Allow multi-select"
                description="Tasks can hold multiple values · subtype = select.multi"
                checked={subtype === "multi"}
                onCheckedChange={(checked) =>
                  handleSubtypeChange(checked ? "multi" : "single")
                }
                testId="custom-field-select-multi-input"
              />
            )}

            {multiSupported && (
              <ToggleRow
                id="cf-multi"
                label="Allow multiple values"
                description="Tasks can hold more than one value for this field."
                checked={Boolean(config.multi)}
                onCheckedChange={(checked) =>
                  setConfig({ ...config, multi: checked })
                }
                testId="custom-field-multi-input"
              />
            )}

            <ToggleRow
              id="cf-required"
              label="Required"
              description={
                nullable
                  ? "Empty allowed · nullable = true"
                  : "Tasks must have a value · nullable = false"
              }
              checked={!nullable}
              onCheckedChange={(checked) => setNullable(!checked)}
              testId="custom-field-required-input"
            />

            <div className="space-y-2">
              <Label>
                Footer aggregation{" "}
                <span className="text-muted-foreground text-xs">
                  (roll-up shown at the bottom of the task list)
                </span>
              </Label>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1.5">
                  <Label
                    htmlFor="cf-footer-fn"
                    className="text-xs text-muted-foreground"
                  >
                    Function
                  </Label>
                  <select
                    id="cf-footer-fn"
                    value={footer?.kind ?? "none"}
                    onChange={(e) =>
                      toggleFooter(
                        e.target.value === "none"
                          ? null
                          : (e.target.value as FooterKind),
                      )
                    }
                    className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    data-testid="custom-field-footer-function"
                  >
                    <option value="none">None</option>
                    {descriptor.footerKinds.map((fk) => (
                      <option key={fk} value={fk}>
                        {FOOTER_LABELS[fk]}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="space-y-1.5">
                  <Label
                    htmlFor="cf-footer-label"
                    className="text-xs text-muted-foreground"
                  >
                    Label override
                  </Label>
                  <Input
                    id="cf-footer-label"
                    value={footer?.label ?? ""}
                    onChange={(e) =>
                      footer &&
                      setFooter({
                        kind: footer.kind,
                        label:
                          e.target.value === "" ? undefined : e.target.value,
                      })
                    }
                    placeholder="Use field name"
                    disabled={!footer}
                    data-testid="custom-field-footer-label"
                  />
                </div>
              </div>
              <div
                className="flex flex-wrap gap-1.5"
                data-testid="custom-field-footer-pills"
              >
                <FooterPill
                  active={footer === null}
                  label="None"
                  onClick={() => toggleFooter(null)}
                />
                {descriptor.footerKinds.map((fk) => (
                  <FooterPill
                    key={fk}
                    active={footer?.kind === fk}
                    label={FOOTER_LABELS[fk]}
                    onClick={() => toggleFooter(fk)}
                  />
                ))}
              </div>
            </div>

            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            {/* Preview pinned to the bottom, set off from the form above. */}
            <div className="mt-auto space-y-3 border-t pt-8">
              <div className="space-y-2">
                <div>
                  <h3 className="text-base font-semibold text-foreground">
                    Preview
                  </h3>
                  <p className="text-xs text-muted-foreground">
                    How this field appears when filling in a task.
                  </p>
                </div>
                <div className="rounded-md border bg-muted/20 p-3" data-testid="custom-field-preview">
                  <CustomFieldValueEditor
                    definition={previewDefinition}
                    value={previewValue}
                    onChange={setPreviewValue}
                    projectIri={projectIri}
                  />
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-2 text-xs">
                <span
                  className={cn("h-1.5 w-1.5 rounded-full", KIND_BADGE[kind].dot)}
                />
                <span className="text-muted-foreground">
                  {kindLabelFor(kind).toLowerCase()} ·{" "}
                  {subtypeLabelFor(kind, subtype).toLowerCase()}
                </span>
                <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground">
                  nullable = {String(nullable)}
                </span>
              </div>
            </div>
          </div>

          <SheetFooter className="flex-row items-center justify-between border-t px-5 py-3">
            {isEdit && onDeleted ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="text-destructive hover:text-destructive"
                onClick={() => void handleDelete()}
                disabled={submitting || deleting}
                data-testid="custom-field-delete"
              >
                <Trash2 className="mr-1 h-3.5 w-3.5" /> Delete field
              </Button>
            ) : (
              <span />
            )}
            <div className="flex gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onOpenChange(false)}
                disabled={submitting || deleting}
              >
                Cancel
              </Button>
              <Button
                type="submit"
                size="sm"
                disabled={submitting || deleting || !name.trim()}
                data-testid="custom-field-save"
              >
                {submitting ? "Saving…" : isEdit ? "Save changes" : "Create field"}
              </Button>
            </div>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
    <ConfirmDialog
      open={conversionLost !== null}
      onOpenChange={(o) => {
        if (!o) setConversionLost(null);
      }}
      title="Some values can't be converted"
      description={
        conversionLost === null
          ? undefined
          : `${conversionLost} task value${conversionLost === 1 ? "" : "s"} can't be converted to the new field type and will be permanently deleted. Values that can be converted are kept.`
      }
      confirmLabel="Convert & delete"
      cancelLabel="Keep editing"
      destructive
      onConfirm={confirmConversion}
    />
    </>
  );
};

const ToggleRow = ({
  id,
  label,
  description,
  checked,
  onCheckedChange,
  testId,
}: {
  id: string;
  label: string;
  description: string;
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
  testId?: string;
}) => (
  <div className="flex items-center justify-between gap-3 rounded-md border border-input p-3">
    <div className="min-w-0">
      <Label htmlFor={id} className="cursor-pointer">
        {label}
      </Label>
      <p className="text-xs text-muted-foreground">{description}</p>
    </div>
    <Switch
      id={id}
      checked={checked}
      onCheckedChange={onCheckedChange}
      data-testid={testId}
    />
  </div>
);

const FooterPill = ({
  active,
  label,
  onClick,
}: {
  active: boolean;
  label: string;
  onClick: () => void;
}) => (
  <button
    type="button"
    onClick={onClick}
    aria-pressed={active}
    className={cn(
      "rounded-full border px-3 py-1 text-xs font-medium uppercase tracking-wide transition",
      active
        ? "border-emerald-500/40 bg-emerald-500/15 text-emerald-300"
        : "border-input text-muted-foreground hover:text-foreground",
    )}
  >
    {label}
  </button>
);

export default CustomFieldSheet;
