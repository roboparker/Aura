import { useState } from "react";
import { Filter } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

/**
 * A compact multi-select filter dropdown: a labelled button (with an active
 * count) opening a checkbox list, with a type-to-filter search box at the top
 * (shown when there are enough options) and a Clear action pinned at the
 * bottom. Shared by the calendar, board, and any other filter bar.
 */
const FilterMultiSelect = ({
  label,
  options,
  selected,
  onChange,
  testId,
}: {
  label: string;
  /** `[value, label]` pairs, already in display order. */
  options: [value: string, label: string][];
  selected: Set<string>;
  onChange: (next: Set<string>) => void;
  testId?: string;
}) => {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const count = selected.size;
  const toggle = (value: string, checked: boolean) => {
    const next = new Set(selected);
    if (checked) next.add(value);
    else next.delete(value);
    onChange(next);
  };
  const q = query.trim().toLowerCase();
  const filtered = q
    ? options.filter(([, optionLabel]) => optionLabel.toLowerCase().includes(q))
    : options;

  return (
    <Popover
      open={open}
      onOpenChange={(o) => {
        setOpen(o);
        if (!o) setQuery("");
      }}
    >
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className={cn("h-8 gap-1", count > 0 && "border-primary/60 text-foreground")}
          data-testid={testId}
        >
          <Filter className="h-3.5 w-3.5" />
          {label}
          {count > 0 && <span className="text-muted-foreground">({count})</span>}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-56 p-0">
        {options.length > 6 && (
          <div className="border-b p-1">
            <Input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={`Search ${label.toLowerCase()}…`}
              className="h-7 text-sm"
              aria-label={`Search ${label.toLowerCase()}`}
            />
          </div>
        )}
        <div className="max-h-56 overflow-y-auto p-1">
          {filtered.length === 0 ? (
            <p className="px-2 py-1.5 text-xs text-muted-foreground">
              {options.length === 0 ? "No options" : "No matches"}
            </p>
          ) : (
            filtered.map(([value, optionLabel]) => (
              <label
                key={value}
                className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent"
              >
                <Checkbox
                  checked={selected.has(value)}
                  onCheckedChange={(c) => toggle(value, c === true)}
                />
                <span className="truncate">{optionLabel}</span>
              </label>
            ))
          )}
        </div>
        <div className="border-t p-1">
          <button
            type="button"
            onClick={() => onChange(new Set())}
            disabled={count === 0}
            className="w-full rounded px-2 py-1.5 text-left text-xs text-muted-foreground hover:bg-accent hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
          >
            Clear{count > 0 ? ` (${count})` : ""}
          </button>
        </div>
      </PopoverContent>
    </Popover>
  );
};

export default FilterMultiSelect;
