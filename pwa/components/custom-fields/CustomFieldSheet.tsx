import {
  ChangeEvent,
  FormEvent,
  useEffect,
  useMemo,
  useState,
} from "react";
import { Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
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
  KIND_DESCRIPTORS,
  KIND_ORDER,
  defaultConfigFor,
  fallbackSubtypeFor,
} from "./kind-editors";
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
  projectIri: string;
  spaceName?: string;
  /** Present when editing an existing field. */
  initial?: CustomFieldDefinition;
  /** Position to assign a newly created field. */
  initialPosition?: number;
  /** Tasks-with-a-value count, shown in the edit header. */
  valueCount?: number;
  onSaved: (def: CustomFieldDefinition) => void;
  onDeleted?: (def: CustomFieldDefinition) => void;
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

const CustomFieldSheet = ({
  open,
  onOpenChange,
  projectIri,
  spaceName,
  initial,
  initialPosition = 0,
  valueCount,
  onSaved,
  onDeleted,
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
  const [optionStats, setOptionStats] = useState<Record<string, number>>({});

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
      setName("");
      setKind("text");
      setSubtype(fallbackSubtypeFor("text"));
      setConfig(defaultConfigFor("text", "text"));
      setNullable(true);
      setFooter(null);
    }
  }, [open, initial]);

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

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const trimmedName = name.trim();
    if (!trimmedName) return;

    setSubmitting(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        name: trimmedName,
        kind,
        subtype,
        config,
        nullable,
        footer,
      };
      let res: Response;
      if (isEdit && initial) {
        res = await fetch(`${ENTRYPOINT}${initial["@id"]}`, {
          method: "PATCH",
          credentials: "include",
          headers: {
            "Content-Type": "application/merge-patch+json",
            Accept: "application/ld+json",
          },
          body: JSON.stringify(body),
        });
      } else {
        res = await fetch(`${ENTRYPOINT}/custom_field_definitions`, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/ld+json" },
          body: JSON.stringify({
            ...body,
            project: projectIri,
            position: initialPosition,
          }),
        });
      }
      if (!res.ok) throw new Error(await errorMessage(res));
      const saved: CustomFieldDefinition = await res.json();
      onSaved(saved);
      onOpenChange(false);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSubmitting(false);
    }
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
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-md"
        data-testid="custom-field-sheet"
      >
        <SheetHeader className="border-b px-5 py-4">
          <SheetTitle className="text-sm font-semibold uppercase tracking-wide">
            {isEdit ? "Edit field" : "New field"}
          </SheetTitle>
          <p className="text-xs text-muted-foreground">
            <span className="font-mono">{handle}</span>
            {isEdit && typeof valueCount === "number" && (
              <> · {valueCount} {valueCount === 1 ? "value" : "values"}</>
            )}
          </p>
        </SheetHeader>

        <form
          onSubmit={handleSubmit}
          className="flex min-h-0 flex-1 flex-col"
        >
          <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
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

            <CustomFieldConfigEditor
              kind={kind}
              subtype={subtype}
              config={config}
              onChange={setConfig}
              onSubtypeChange={handleSubtypeChange}
              spaceName={spaceName}
              optionStats={optionStats}
            />

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
                  (shown at the bottom of the task list)
                </span>
              </Label>
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
              {footer && (
                <Input
                  value={footer.label ?? ""}
                  onChange={(e) =>
                    setFooter({
                      kind: footer.kind,
                      label: e.target.value === "" ? undefined : e.target.value,
                    })
                  }
                  placeholder="Label override (defaults to field name)"
                  data-testid="custom-field-footer-label"
                />
              )}
            </div>

            {error && (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}
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
      "rounded-full border px-3 py-1 text-xs font-medium transition",
      active
        ? "border-primary bg-primary text-primary-foreground"
        : "border-input text-muted-foreground hover:text-foreground",
    )}
  >
    {label}
  </button>
);

export default CustomFieldSheet;
