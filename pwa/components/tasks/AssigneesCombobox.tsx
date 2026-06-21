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
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";

export interface AssigneeOption extends AvatarUser {
  "@id": string;
  id: string;
  email: string;
}

interface AssigneesComboboxProps {
  value: AssigneeOption[];
  options: AssigneeOption[];
  onChange: (nextIris: string[]) => void | Promise<void>;
  /** Used to label the input for screen readers. */
  subjectLabel?: string;
  className?: string;
  /** When provided, clicking an assignee's avatar invokes this (e.g. to filter). */
  onAvatarClick?: (assignee: AssigneeOption) => void;
}

const AssigneesCombobox = ({
  value,
  options,
  onChange,
  subjectLabel,
  className,
  onAvatarClick,
}: AssigneesComboboxProps) => {
  const handleChange = (next: AssigneeOption[]) => {
    void onChange(next.map((u) => u["@id"]));
  };

  // Always a combobox: assignees render as avatar + name chips and the input
  // carries the placeholder prompt, rather than swapping a collapsed avatar
  // stack for an editor on click — matching the tags field.
  return (
    <div className={className}>
      <Combobox<AssigneeOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        itemToStringLabel={(u) => displayName(u)}
        itemToStringValue={(u) => u["@id"]}
      >
        <ComboboxChips>
          <ComboboxValue>
            {value.map((u) => (
              <ComboboxChip
                key={u["@id"]}
                aria-label={displayName(u)}
                className="gap-1 px-2"
                data-testid="task-assignee-chip"
              >
                {onAvatarClick ? (
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation();
                      onAvatarClick(u);
                    }}
                    aria-label={`Filter by ${displayName(u)}`}
                    title={displayName(u)}
                    className="rounded-full border-0 bg-transparent p-0"
                  >
                    <UserAvatar user={u} size="sm" className="h-4 w-4" />
                  </button>
                ) : (
                  <UserAvatar user={u} size="sm" className="h-4 w-4" />
                )}
                <span className="truncate max-w-[10rem]">{displayName(u)}</span>
              </ComboboxChip>
            ))}
          </ComboboxValue>
          <ComboboxChipsInput
            placeholder={value.length === 0 ? "Assign people…" : ""}
            aria-label={
              subjectLabel ? `Assignees for "${subjectLabel}"` : "Assignees"
            }
            // Chrome ignores `autoComplete="off"` on text inputs; an unrecognised
            // hint makes its autofill bail. name/data-form-type are for password
            // managers (LastPass, 1Password).
            autoComplete="nope"
            autoCorrect="off"
            autoCapitalize="off"
            spellCheck={false}
            name="madori-assignee-search"
            data-form-type="other"
            data-lpignore="true"
            data-1p-ignore="true"
          />
        </ComboboxChips>
        <ComboboxContent>
          <ComboboxEmpty>No assignable users.</ComboboxEmpty>
          <ComboboxList>
            {(u: AssigneeOption) => (
              <ComboboxItem key={u["@id"]} value={u}>
                <UserAvatar user={u} size="sm" className="h-5 w-5" />
                <span className="min-w-0 flex-1 truncate">{displayName(u)}</span>
                <span className="text-xs text-muted-foreground truncate">
                  {u.email}
                </span>
              </ComboboxItem>
            )}
          </ComboboxList>
        </ComboboxContent>
      </Combobox>
    </div>
  );
};

export default AssigneesCombobox;
