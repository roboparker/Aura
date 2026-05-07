import {
  ChangeEvent,
  FormEvent,
  useCallback,
  useEffect,
  useState,
} from "react";
import { Pencil, Plus, Trash2, X } from "lucide-react";
import { ENTRYPOINT } from "@/config/entrypoint";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

export type CustomFieldType =
  | "text"
  | "number"
  | "date"
  | "dropdown"
  | "checkbox";

export interface CustomFieldDefinition {
  "@id": string;
  id: string;
  name: string;
  type: CustomFieldType;
  options: string[] | null;
  position: number;
  required: boolean;
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

interface Props {
  projectIri: string;
  isProjectOwner: boolean;
}

const TYPE_LABEL: Record<CustomFieldType, string> = {
  text: "Text",
  number: "Number",
  date: "Date",
  dropdown: "Dropdown",
  checkbox: "Checkbox",
};

const TYPE_ORDER: CustomFieldType[] = [
  "text",
  "number",
  "date",
  "dropdown",
  "checkbox",
];

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
  [...list].sort((a, b) => a.position - b.position || a.name.localeCompare(b.name));

const CustomFieldsManager = ({ projectIri, isProjectOwner }: Props) => {
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
      setDefs(
        sortByPosition(data.member ?? data["hydra:member"] ?? []),
      );
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

  const nextPosition = defs.length > 0 ? Math.max(...defs.map((d) => d.position)) + 1 : 0;

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
              {isProjectOwner
                ? " Add the first one to start collecting structured data on tasks."
                : " Only the project owner can add fields."}
            </p>
            {isProjectOwner && (
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
                          <Badge variant="outline">{TYPE_LABEL[def.type]}</Badge>
                          {def.required && (
                            <Badge variant="secondary">Required</Badge>
                          )}
                        </div>
                        {def.type === "dropdown" && def.options && (
                          <p className="text-xs text-muted-foreground">
                            Options: {def.options.join(", ")}
                          </p>
                        )}
                      </div>
                      {isProjectOwner && (
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

          {isProjectOwner && !showComposer && (
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

      {isProjectOwner && showComposer && (
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
  const [type, setType] = useState<CustomFieldType>(initial?.type ?? "text");
  const [required, setRequired] = useState(initial?.required ?? false);
  const [options, setOptions] = useState<string[]>(initial?.options ?? []);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isEdit = Boolean(initial);

  const setOptionAt = (idx: number, value: string) => {
    setOptions((prev) => prev.map((o, i) => (i === idx ? value : o)));
  };

  const addOption = () => setOptions((prev) => [...prev, ""]);
  const removeOption = (idx: number) =>
    setOptions((prev) => prev.filter((_, i) => i !== idx));

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const trimmedName = name.trim();
    if (!trimmedName) return;

    const cleanedOptions =
      type === "dropdown"
        ? options.map((o) => o.trim()).filter((o) => o.length > 0)
        : null;

    if (type === "dropdown" && cleanedOptions && cleanedOptions.length === 0) {
      setError("Add at least one option for a dropdown field.");
      return;
    }

    setSubmitting(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        name: trimmedName,
        type,
        required,
        options: cleanedOptions,
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
          <div className="space-y-1.5">
            <Label htmlFor="cf-type">Type</Label>
            <select
              id="cf-type"
              value={type}
              onChange={(e: ChangeEvent<HTMLSelectElement>) => {
                const next = e.target.value as CustomFieldType;
                setType(next);
                if (next === "dropdown" && options.length === 0) {
                  setOptions([""]);
                }
              }}
              className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              data-testid="custom-field-type-input"
            >
              {TYPE_ORDER.map((t) => (
                <option key={t} value={t}>
                  {TYPE_LABEL[t]}
                </option>
              ))}
            </select>
          </div>
          {type === "dropdown" && (
            <div className="space-y-2" data-testid="custom-field-options">
              <Label>Options</Label>
              {options.map((opt, idx) => (
                <div key={idx} className="flex items-center gap-2">
                  <Input
                    value={opt}
                    onChange={(e) => setOptionAt(idx, e.target.value)}
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
          )}
          <div className="flex items-center gap-2">
            <Checkbox
              id="cf-required"
              checked={required}
              onCheckedChange={(checked) => setRequired(Boolean(checked))}
              data-testid="custom-field-required-input"
            />
            <Label htmlFor="cf-required">Required</Label>
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
