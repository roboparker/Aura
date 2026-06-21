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
  const handleChange = (next: TagOption[]) => {
    void onChange(next.map((tag) => tag["@id"]));
  };

  // Always a combobox: selected tags render as coloured chips and the input
  // carries the placeholder prompt, rather than swapping a read-only display
  // for an editor on click.
  return (
    <div className={className}>
      <Combobox<TagOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        itemToStringLabel={(tag) => tag.title}
        itemToStringValue={(tag) => tag["@id"]}
      >
        <ComboboxChips>
          <ComboboxValue>
            {value.map((tag) => (
              <ComboboxChip
                key={tag["@id"]}
                aria-label={tag.title}
                className="px-2 text-white [&_[data-slot=combobox-chip-remove]]:hover:!bg-transparent"
                style={{ backgroundColor: tag.color }}
                data-testid="task-tag"
              >
                {tag.title}
              </ComboboxChip>
            ))}
          </ComboboxValue>
          <ComboboxChipsInput
            placeholder={value.length === 0 ? "Add tags…" : ""}
            aria-label={subjectLabel ? `Tags for "${subjectLabel}"` : "Tags"}
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
