import { useMemo, useState } from "react";
import { Plus } from "lucide-react";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Input } from "@/components/ui/input";
import type {
  CustomFieldDefinition,
  CustomFieldKind,
  CustomFieldSubtype,
} from "@/components/custom-fields/types";

/** The "create a new field" type presets offered in the menu. */
const NEW_TYPES: Array<{
  label: string;
  kind: CustomFieldKind;
  subtype: CustomFieldSubtype;
}> = [
  { label: "Text", kind: "text", subtype: "text" },
  { label: "Number", kind: "numeric", subtype: "int" },
  { label: "Decimal", kind: "numeric", subtype: "float" },
  { label: "Money", kind: "numeric", subtype: "money" },
  { label: "Date", kind: "date", subtype: "date" },
  { label: "Checkbox", kind: "boolean", subtype: "boolean" },
  { label: "Select", kind: "select", subtype: "single" },
  { label: "Person", kind: "reference", subtype: "user" },
];

interface AddFieldMenuProps {
  /** Fields that exist on the project but aren't shown in the list view. */
  hiddenFields: CustomFieldDefinition[];
  /** Reveal an existing hidden field in the list view. */
  onEnable: (def: CustomFieldDefinition) => void;
  /** Start creating a new field of the chosen type (opens the editor modal). */
  onCreate: (kind: CustomFieldKind, subtype: CustomFieldSubtype) => void;
}

/**
 * The "+" at the head of the list view's trailing column. Opens a searchable
 * menu to either re-show a hidden field or create a new one by type (which
 * opens the field editor preset to that type).
 */
const AddFieldMenu = ({ hiddenFields, onEnable, onCreate }: AddFieldMenuProps) => {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");

  const needle = query.trim().toLowerCase();
  const hidden = useMemo(
    () => hiddenFields.filter((f) => f.name.toLowerCase().includes(needle)),
    [hiddenFields, needle],
  );
  const types = NEW_TYPES.filter((t) => t.label.toLowerCase().includes(needle));

  const close = () => {
    setOpen(false);
    setQuery("");
  };

  return (
    <Popover
      open={open}
      onOpenChange={(o) => {
        setOpen(o);
        if (!o) setQuery("");
      }}
    >
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label="Add field"
          title="Add field"
          className="rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
          data-testid="add-field-trigger"
        >
          <Plus className="h-4 w-4" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-64 p-2">
        <Input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Find or create a field…"
          className="mb-2 h-8"
          autoFocus
          aria-label="Find or create a field"
        />
        <div className="max-h-72 overflow-y-auto">
          {hidden.length > 0 && (
            <div className="mb-1">
              <p className="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Hidden fields
              </p>
              {hidden.map((f) => (
                <button
                  key={f.id}
                  type="button"
                  onClick={() => {
                    onEnable(f);
                    close();
                  }}
                  className="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
                  data-testid="add-field-hidden"
                >
                  <span className="truncate">{f.name}</span>
                  <span className="shrink-0 text-xs text-muted-foreground">Show</span>
                </button>
              ))}
            </div>
          )}
          <p className="px-2 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            New field
          </p>
          {types.map((t) => (
            <button
              key={t.label}
              type="button"
              onClick={() => {
                onCreate(t.kind, t.subtype);
                close();
              }}
              className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
              data-testid="add-field-new"
            >
              <Plus className="h-3.5 w-3.5 text-muted-foreground" />
              {t.label}
            </button>
          ))}
          {hidden.length === 0 && types.length === 0 && (
            <p className="px-2 py-2 text-sm text-muted-foreground">No matches.</p>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
};

export default AddFieldMenu;
