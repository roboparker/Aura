import { useState } from "react";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";
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
  useComboboxAnchor,
} from "@/components/ui/combobox";
import type { Elevation } from "@/lib/elevation";

export interface TagOption {
  "@id": string;
  id: string;
  title: string;
  color: string;
}

interface TagsComboboxProps {
  value: TagOption[];
  options: TagOption[];
  /** Second arg gives the resolved tag objects (handy when a just-created tag
   *  isn't in `options` yet, so callers needn't re-resolve from iris). */
  onChange: (nextIris: string[], tags: TagOption[]) => void | Promise<void>;
  /** Used to label the input for screen readers. */
  subjectLabel?: string;
  className?: string;
  /** Surface elevation; 0 = inline (flush in a table cell). */
  elevation?: Elevation;
  /** Create a tag from free text (Enter / comma) and return it, or null on
   *  failure. When omitted, typing a non-existent tag does nothing. */
  onCreate?: (title: string) => Promise<TagOption | null>;
}

const TagsCombobox = ({
  value,
  options,
  onChange,
  subjectLabel,
  className,
  elevation = 1,
  onCreate,
}: TagsComboboxProps) => {
  const handleChange = (next: TagOption[]) => {
    void onChange(
      next.map((tag) => tag["@id"]),
      next,
    );
  };
  // Anchor the dropdown to the chips field so it lines up (same left edge +
  // width), matching the assignees field.
  const anchor = useComboboxAnchor();
  // Controlled query so we can clear it after creating/adding a tag.
  const [query, setQuery] = useState("");

  // Enter / comma: add the typed text — an existing tag (exact match, case
  // insensitive) or a freshly created one.
  const commitTyped = async () => {
    const text = query.trim();
    if (!text) return;
    const existing = options.find(
      (t) => t.title.toLowerCase() === text.toLowerCase(),
    );
    if (existing) {
      if (!value.some((v) => v["@id"] === existing["@id"])) {
        handleChange([...value, existing]);
      }
    } else if (onCreate) {
      const created = await onCreate(text);
      if (created) handleChange([...value, created]);
    }
    setQuery("");
  };

  // Always a combobox: selected tags render as coloured chips and the input
  // carries the placeholder prompt, rather than swapping a read-only display
  // for an editor on click.
  return (
    <div className={cn("group/field relative", className)}>
      <Combobox<TagOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        inputValue={query}
        onInputValueChange={setQuery}
        itemToStringLabel={(tag) => tag.title}
        itemToStringValue={(tag) => tag["@id"]}
      >
        <ComboboxChips
          ref={anchor}
          elevation={elevation}
          className={
            elevation === 0 ? "group-hover/field:border-input" : undefined
          }
        >
          <ComboboxValue>
            {value.map((tag) => (
              <ComboboxChip
                key={tag["@id"]}
                aria-label={tag.title}
                className="px-2 text-white [&_[data-slot=combobox-chip-remove]]:hover:!bg-transparent"
                style={{ backgroundColor: tag.color }}
                showRemove={elevation !== 0}
                data-testid="task-tag"
              >
                {tag.title}
              </ComboboxChip>
            ))}
          </ComboboxValue>
          <ComboboxChipsInput
            placeholder={value.length === 0 ? "Add tags…" : ""}
            aria-label={subjectLabel ? `Tags for "${subjectLabel}"` : "Tags"}
            onKeyDown={(e) => {
              if ((e.key === "Enter" || e.key === ",") && query.trim()) {
                e.preventDefault();
                void commitTyped();
              }
            }}
            // Chrome ignores `autoComplete="off"` on text inputs and was
            // suggesting saved email addresses on top of our popover. Setting an
            // unrecognised hint string ("nope") makes Chrome's autofill module
            // bail out; the name/data-form-type hints are for password managers.
            autoComplete="nope"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            name="madori-tag-search"
            data-form-type="other"
            data-lpignore="true"
            data-1p-ignore="true"
          />
        </ComboboxChips>
        <ComboboxContent anchor={anchor}>
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
      {/* Inline field-level clear — styled + positioned like the date cell's. */}
      {elevation === 0 && value.length > 0 && (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation();
            void onChange([], []);
          }}
          aria-label="Clear tags"
          className="absolute right-1 top-1/2 z-10 -translate-y-1/2 rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover/field:opacity-100 focus-visible:opacity-100"
          data-testid="task-tags-clear"
        >
          <X className="size-3.5" />
        </button>
      )}
    </div>
  );
};

export default TagsCombobox;
