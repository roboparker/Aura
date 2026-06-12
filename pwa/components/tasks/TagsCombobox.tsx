import { useEffect, useRef, useState } from "react";
import { Pencil, Plus } from "lucide-react";

import {
  Combobox,
  ComboboxChip,
  ComboboxChips,
  ComboboxChipsInput,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxItem,
  ComboboxList,
  ComboboxValue,
} from "@/components/ui/combobox";
import { cn } from "@/lib/utils";

export interface TagOption {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface TagsComboboxProps {
  value: TagOption[];
  options: TagOption[];
  onChange: (nextIris: string[]) => void | Promise<void>;
  /** Used to label the input for screen readers. */
  subjectLabel?: string;
  className?: string;
}

const TagsCombobox = ({
  value,
  options,
  onChange,
  subjectLabel,
  className,
}: TagsComboboxProps) => {
  // Edit mode: when false we render the chips as a flat list (no border, no
  // input) plus a small "Add"/"Edit" affordance. When true we render the
  // full Combobox chrome (bordered chip-input + popover) so the user can
  // search and pick tags.
  const [isEditing, setIsEditing] = useState(false);
  const containerRef = useRef<HTMLDivElement | null>(null);

  // Click-outside dismissal. The popover content is portaled to <body>, so
  // checking ref.contains() alone isn't enough — we also keep the editor
  // open while the click lands inside `[data-slot=combobox-content]`.
  useEffect(() => {
    if (!isEditing) return;
    const handle = (event: PointerEvent) => {
      const target = event.target as Element | null;
      if (!target) return;
      if (containerRef.current?.contains(target)) return;
      if (target.closest('[data-slot="combobox-content"]')) return;
      setIsEditing(false);
    };
    document.addEventListener("pointerdown", handle, true);
    return () => document.removeEventListener("pointerdown", handle, true);
  }, [isEditing]);

  // Focus the chip-input as soon as we enter edit mode so the user can type
  // immediately. Querying the DOM is fine here — Base UI renders the input
  // synchronously inside ComboboxChips.
  useEffect(() => {
    if (!isEditing) return;
    const input = containerRef.current?.querySelector<HTMLInputElement>(
      '[data-slot="combobox-chip-input"]',
    );
    input?.focus();
  }, [isEditing]);

  const handleChange = (next: TagOption[]) => {
    void onChange(next.map((tag) => tag["@id"]));
  };

  return (
    <div ref={containerRef} className={className}>
      <Combobox<TagOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        itemToStringLabel={(tag) => tag.title}
        itemToStringValue={(tag) => tag["@id"]}
      >
        <ComboboxChips
          className={cn(
            // Strip the bordered "input box" look until we're editing. Chips
            // themselves still render with their own coloured background and
            // X-to-remove button, so the user can still prune tags without
            // entering edit mode.
            !isEditing &&
              "min-h-0 border-transparent bg-transparent px-0 py-0 shadow-none focus-within:border-transparent focus-within:ring-0 dark:bg-transparent has-data-[slot=combobox-chip]:px-0",
          )}
        >
          <ComboboxValue>
            {value.map((tag) => (
              // The chip itself becomes the coloured pill so the built-in
              // remove button (X) sits on the same coloured background as
              // the tag — no contrasting muted "frame" around it.
              <ComboboxChip
                key={tag["@id"]}
                aria-label={tag.title}
                // The X uses the shadcn ghost button which paints `bg-accent`
                // on hover. On a coloured chip that grey hover swatch reads
                // as a hole — kill it so only the icon's opacity changes.
                className="px-2 text-white [&_[data-slot=combobox-chip-remove]]:hover:!bg-transparent"
                style={{ backgroundColor: tag.color }}
                data-testid="task-tag"
              >
                {tag.title}
              </ComboboxChip>
            ))}
          </ComboboxValue>
          {isEditing ? (
            <ComboboxChipsInput
              placeholder={value.length === 0 ? "Add tags…" : ""}
              aria-label={subjectLabel ? `Tags for "${subjectLabel}"` : "Tags"}
              onKeyDown={(event) => {
                if (event.key === "Escape") {
                  event.preventDefault();
                  setIsEditing(false);
                }
              }}
              // Chrome ignores `autoComplete="off"` on text inputs and was
              // suggesting saved email addresses on top of our popover.
              // Setting an unrecognised hint string ("nope") makes Chrome's
              // autofill module treat the field as "no known type" and bail
              // out without falling back to email/contact suggestions. The
              // accompanying `name`/`data-form-type` are for password
              // managers (LastPass, 1Password) that respect those hints.
              autoComplete="nope"
              autoCorrect="off"
              autoCapitalize="off"
              spellCheck={false}
              name="madori-tag-search"
              data-form-type="other"
              data-lpignore="true"
              data-1p-ignore="true"
            />
          ) : (
            // Trigger to switch into edit mode. Renders compactly so it
            // doesn't compete with the chips visually.
            <button
              type="button"
              onClick={() => setIsEditing(true)}
              aria-label={
                subjectLabel
                  ? `Edit tags for "${subjectLabel}"`
                  : value.length === 0
                    ? "Add tags"
                    : "Edit tags"
              }
              className={cn(
                "inline-flex items-center gap-1 rounded-sm px-1.5 py-0.5 text-xs",
                value.length === 0
                  ? "italic text-muted-foreground/60 hover:text-muted-foreground"
                  : "text-muted-foreground/60 hover:text-foreground",
              )}
              data-testid="task-tags-edit"
            >
              {value.length === 0 ? (
                <>
                  <Plus className="h-3 w-3" />
                  Add tags
                </>
              ) : (
                <Pencil className="h-3 w-3" />
              )}
            </button>
          )}
        </ComboboxChips>
        <ComboboxContent>
          <ComboboxEmpty>No tags found.</ComboboxEmpty>
          <ComboboxList>
            {(tag: TagOption) => (
              <ComboboxItem key={tag["@id"]} value={tag}>
                <span
                  className="inline-block h-3 w-3 shrink-0 rounded"
                  style={{ backgroundColor: tag.color }}
                  aria-hidden="true"
                />
                <span>{tag.title}</span>
              </ComboboxItem>
            )}
          </ComboboxList>
        </ComboboxContent>
      </Combobox>
    </div>
  );
};

export default TagsCombobox;
