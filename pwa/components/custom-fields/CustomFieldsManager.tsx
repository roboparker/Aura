import {
  ChangeEvent,
  FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  CustomFieldConfigEditor,
  KIND_DESCRIPTORS,
  KIND_ORDER,
  defaultConfigFor,
  fallbackSubtypeFor,
} from "./kind-editors";
import type {
  CustomFieldConfig,
  CustomFieldDefinition,
  CustomFieldKind,
  CustomFieldSubtype,
  FooterDescriptor,
  FooterKind,
} from "./types";

/**
 * Schema editor for a project's custom field catalogue. Talks to
 * `/custom_field_definitions` with the {kind, subtype, config, footer,
 * nullable} payload the API now expects (#227). Per-kind config
 * editors live in `kind-editors.tsx`; this component owns the shared
 * scaffolding (list, composer, footer descriptor).
 */
interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  projectIri: string;
  isSpaceAdmin: boolean;
}

const errorMessage = async (res: Response): Promise<string> => {
  const data = await res.json().catch(() => ({}));
  return (
    data.detail ||
    data.description ||
    data["hydra:description"] ||
    "Request failed."
  );
};

const sortByPosition = (
  list: CustomFieldDefinition[],
): CustomFieldDefinition[] =>
  [...list].sort(
    (a, b) => a.position - b.position || a.name.localeCompare(b.name),
  );

const describeType = (def: CustomFieldDefinition): string => {
  const kindLabel = KIND_DESCRIPTORS[def.kind]?.label ?? def.kind;
  const subtypeLabel =
    KIND_DESCRIPTORS[def.kind]?.subtypes.find((s) => s.value === def.subtype)
      ?.label ?? def.subtype;
  return `${kindLabel} · ${subtypeLabel}`;
};

const CustomFieldsManager = ({ projectIri, isSpaceAdmin }: Props) => {
  const [defs, setDefs] = useState<CustomFieldDefinition[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [showComposer, setShowComposer] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setLoadError(null);
    try {
      const url = `${ENTRYPOINT}/custom_field_definitions?project=${encodeURIComponent(projectIri)}`;
      const res = await fetch(url, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load custom fields.");
      const data: Collection<CustomFieldDefinition> = await res.json();
      setDefs(sortByPosition(data.member ?? data["hydra:member"] ?? []));
    } catch (err) {
      setLoadError(
        err instanceof Error ? err.message : "Failed to load custom fields.",
      );
    } finally {
      setIsLoading(false);
    }
  }, [projectIri]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleCreated = (created: CustomFieldDefinition) => {
    setDefs((prev) => sortByPosition([...prev, created]));
    setShowComposer(false);
  };

  const handleUpdated = (updated: CustomFieldDefinition) => {
    setDefs((prev) =>
      sortByPosition(
        prev.map((d) => (d["@id"] === updated["@id"] ? updated : d)),
      ),
    );
    setEditingId(null);
  };

  const handleDeleted = async (def: CustomFieldDefinition) => {
    if (
      !window.confirm(
        `Delete custom field "${def.name}"? Existing values on tasks will also be removed.`,
      )
    ) {
      return;
    }
    try {
      const res = await fetch(`${ENTRYPOINT}${def["@id"]}`, {
        method: "DELETE",
        credentials: "include",
      });
      if (!res.ok) throw new Error(await errorMessage(res));
      setDefs((prev) => prev.filter((d) => d["@id"] !== def["@id"]));
    } catch (err) {
      window.alert(err instanceof Error ? err.message : "Failed to delete.");
    }
  };

  const nextPosition =
    defs.length > 0 ? Math.max(...defs.map((d) => d.position)) + 1 : 0;

  return (
    <div className="space-y-4" data-testid="custom-fields-manager">
      {loadError && (
        <Alert variant="destructive">
          <AlertDescription>{loadError}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-muted-foreground text-sm">Loading…</p>
      ) : defs.length === 0 && !showComposer ? (
        <Card>
          <CardContent className="pt-6 space-y-3">
            <p className="text-muted-foreground text-sm">
              No custom fields yet.
              {isSpaceAdmin
                ? " Add the first one to start collecting structured data on tasks."
                : " Only space admins can add fields."}
            </p>
            {isSpaceAdmin && (
              <Button
                type="button"
                size="sm"
                onClick={() => setShowComposer(true)}
                data-testid="custom-field-add"
              >
                <Plus className="h-3.5 w-3.5 mr-1" /> Add field
              </Button>
            )}
          </CardContent>
        </Card>
      ) : (
        <>
          <ul className="space-y-2" data-testid="custom-field-list">
            {defs.map((def) =>
              editingId === def["@id"] ? (
                <li key={def["@id"]} data-testid="custom-field-item">
                  <CustomFieldComposer
                    projectIri={projectIri}
                    initial={def}
                    onSaved={handleUpdated}
                    onCancel={() => setEditingId(null)}
                  />
                </li>
              ) : (
                <li key={def["@id"]} data-testid="custom-field-item">
                  <Card>
                    <CardContent className="pt-4 pb-4 flex flex-wrap items-start justify-between gap-3">
                      <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span
                            className="font-medium"
                            data-testid="custom-field-name"
                          >
                            {def.name}
                          </span>
                          <Badge variant="outline">{describeType(def)}</Badge>
                          {!def.nullable && (
                            <Badge variant="secondary">Required</Badge>
                          )}
                          {def.config.multi && (
                            <Badge variant="secondary">Multi-value</Badge>
                          )}
                          {def.footer && (
                            <Badge variant="secondary">
                              Footer: {def.footer.kind}
                            </Badge>
                          )}
                        </div>
                        {def.kind === "select" && def.config.options && (
                          <p className="text-xs text-muted-foreground">
                            Options:{" "}
                            {def.config.options.map((o) => o.label).join(", ")}
                          </p>
                        )}
                      </div>
                      {isSpaceAdmin && (
                        <div className="flex gap-2">
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setEditingId(def["@id"])}
                            data-testid="custom-field-edit"
                          >
                            <Pencil className="h-3.5 w-3.5 mr-1" /> Edit
                          </Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => void handleDeleted(def)}
                            className="text-destructive hover:text-destructive"
                            data-testid="custom-field-delete"
                          >
                            <Trash2 className="h-3.5 w-3.5 mr-1" /> Delete
                          </Button>
                        </div>
                      )}
                    </CardContent>
                  </Card>
                </li>
              ),
            )}
          </ul>

          {isSpaceAdmin && !showComposer && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={() => setShowComposer(true)}
              data-testid="custom-field-add"
            >
              <Plus className="h-3.5 w-3.5 mr-1" /> Add field
            </Button>
          )}
        </>
      )}

      {isSpaceAdmin && showComposer && (
        <CustomFieldComposer
          projectIri={projectIri}
          initialPosition={nextPosition}
          onSaved={handleCreated}
          onCancel={() => setShowComposer(false)}
        />
      )}
    </div>
  );
};

interface ComposerProps {
  projectIri: string;
  initial?: CustomFieldDefinition;
  initialPosition?: number;
  onSaved: (def: CustomFieldDefinition) => void;
  onCancel: () => void;
}

const CustomFieldComposer = ({
  projectIri,
  initial,
  initialPosition = 0,
  onSaved,
  onCancel,
}: ComposerProps) => {
  const [name, setName] = useState(initial?.name ?? "");
  const [kind, setKind] = useState<CustomFieldKind>(initial?.kind ?? "text");
  const [subtype, setSubtype] = useState<CustomFieldSubtype>(
    initial?.subtype ?? fallbackSubtypeFor("text"),
  );
  const [config, setConfig] = useState<CustomFieldConfig>(
    initial?.config ?? defaultConfigFor("text", "text"),
  );
  const [nullable, setNullable] = useState(initial?.nullable ?? true);
  const [footer, setFooter] = useState<FooterDescriptor | null>(
    initial?.footer ?? null,
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEdit = Boolean(initial);
  const descriptor = KIND_DESCRIPTORS[kind];

  const multiSupported = useMemo(() => {
    if (!descriptor.supportsMulti) return false;
    if (descriptor.noMultiSubtypes?.includes(subtype)) return false;
    // select.multi forces multi=true; select.single forces false.
    if (kind === "select") return false;
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
      // Keep options across single<->multi switches; just flip the
      // multi flag in config since it's intrinsic to the subtype.
      setConfig((prev) => ({ ...prev, multi: next === "multi" }));
    } else if (descriptor.noMultiSubtypes?.includes(next)) {
      setConfig((prev) => ({ ...prev, multi: false }));
    }
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
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to save.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Card data-testid="custom-field-composer">
      <CardContent className="pt-6">
        <form onSubmit={handleSubmit} className="space-y-3">
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

          <div className="grid grid-cols-2 gap-2">
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
          />

          {multiSupported && (
            <div className="flex items-center gap-2">
              <Checkbox
                id="cf-multi"
                checked={Boolean(config.multi)}
                onCheckedChange={(checked) =>
                  setConfig({ ...config, multi: Boolean(checked) })
                }
                data-testid="custom-field-multi-input"
              />
              <Label htmlFor="cf-multi">Allow multiple values</Label>
            </div>
          )}

          <div className="flex items-center gap-2">
            <Checkbox
              id="cf-required"
              checked={!nullable}
              onCheckedChange={(checked) => setNullable(!checked)}
              data-testid="custom-field-required-input"
            />
            <Label htmlFor="cf-required">Required</Label>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="cf-footer">
              Footer aggregation{" "}
              <span className="text-muted-foreground text-xs">(optional)</span>
            </Label>
            <select
              id="cf-footer"
              value={footer?.kind ?? ""}
              onChange={(e) => {
                const v = e.target.value;
                setFooter(v === "" ? null : { kind: v as FooterKind });
              }}
              className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              data-testid="custom-field-footer-input"
            >
              <option value="">No footer</option>
              {descriptor.footerKinds.map((fk) => (
                <option key={fk} value={fk}>
                  {fk}
                </option>
              ))}
            </select>
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}
          <div className="flex gap-2">
            <Button
              type="submit"
              size="sm"
              disabled={submitting || !name.trim()}
              data-testid="custom-field-save"
            >
              {submitting ? "Saving…" : isEdit ? "Save" : "Create"}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              onClick={onCancel}
              disabled={submitting}
            >
              Cancel
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
};

export default CustomFieldsManager;
