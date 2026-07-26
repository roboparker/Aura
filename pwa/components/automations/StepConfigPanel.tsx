import type { GraphNode, StepConfigField, StepDefinition } from "@/lib/automationTypes";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";

const SELECT_CLASS =
  "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring";

export interface PickerOption {
  value: string;
  label: string;
}

interface Props {
  node: GraphNode;
  definition: StepDefinition | null;
  readOnly: boolean;
  /** Board sections, for `section` fields. */
  sections: PickerOption[];
  /** Space members, for `space_member` fields. */
  members: PickerOption[];
  onChange: (config: Record<string, unknown>) => void;
}

/**
 * Edits one step's settings from its **server-declared schema** (#764).
 *
 * Knows nothing about any particular step — same approach as the dashboard
 * widget editor. That's what keeps the drop-in promise honest: a new trigger or
 * action written on the server gets a working editor here for free, as long as
 * its fields use the shared control vocabulary.
 *
 * The two entity-shaped controls (`section`, `space_member`) are the exception,
 * and they're deliberately generic rather than per-step: they render whatever
 * options the surrounding board supplies.
 */
const StepConfigPanel = ({
  node,
  definition,
  readOnly,
  sections,
  members,
  onChange,
}: Props) => {
  if (!definition) {
    return (
      <p className="text-sm text-muted-foreground">
        This step (<code>{node.type}</code>) isn&apos;t available on this server,
        so it can&apos;t be edited. Remove it, or restore whatever provided it.
      </p>
    );
  }

  if (definition.configSchema.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        {definition.description} Nothing to configure.
      </p>
    );
  }

  const set = (key: string, value: unknown) =>
    onChange({ ...node.config, [key]: value });

  const field = (schema: StepConfigField) => {
    const value = node.config[schema.key];
    const id = `step-${node.id}-${schema.key}`;

    // Entity pickers get their options from the board, not the schema.
    if (schema.type === "section" || schema.type === "space_member") {
      const options = schema.type === "section" ? sections : members;
      return (
        <div key={schema.key} className="space-y-1.5">
          <Label htmlFor={id}>{schema.label}</Label>
          <select
            id={id}
            className={SELECT_CLASS}
            disabled={readOnly}
            value={typeof value === "string" ? value : ""}
            onChange={(e) => set(schema.key, e.target.value)}
          >
            <option value="">{schema.placeholder ?? "Choose…"}</option>
            {options.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </div>
      );
    }

    switch (schema.type) {
      case "boolean":
        return (
          <div key={schema.key} className="flex items-center justify-between gap-3">
            <Label htmlFor={id}>{schema.label}</Label>
            <Switch
              id={id}
              disabled={readOnly}
              checked={value === true}
              onCheckedChange={(checked) => set(schema.key, checked)}
            />
          </div>
        );

      case "select":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <select
              id={id}
              className={SELECT_CLASS}
              disabled={readOnly}
              value={String(value ?? "")}
              onChange={(e) => {
                // Options carry their own value type; a numeric one has to go
                // back as a number or the server's is_int() check rejects it.
                const picked = schema.options?.find(
                  (o) => String(o.value) === e.target.value,
                );
                set(schema.key, picked ? picked.value : e.target.value);
              }}
            >
              {schema.options?.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
        );

      case "number":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Input
              id={id}
              type="number"
              disabled={readOnly}
              min={schema.min}
              max={schema.max}
              value={typeof value === "number" ? value : ""}
              onChange={(e) =>
                set(schema.key, e.target.value === "" ? undefined : Number(e.target.value))
              }
            />
          </div>
        );

      case "textarea":
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Textarea
              id={id}
              rows={4}
              disabled={readOnly}
              placeholder={schema.placeholder}
              value={typeof value === "string" ? value : ""}
              onChange={(e) => set(schema.key, e.target.value)}
            />
          </div>
        );

      default:
        return (
          <div key={schema.key} className="space-y-1.5">
            <Label htmlFor={id}>{schema.label}</Label>
            <Input
              id={id}
              disabled={readOnly}
              placeholder={schema.placeholder}
              value={typeof value === "string" ? value : ""}
              onChange={(e) => set(schema.key, e.target.value)}
            />
          </div>
        );
    }
  };

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted-foreground">{definition.description}</p>
      {definition.configSchema.map(field)}
    </div>
  );
};

export default StepConfigPanel;
