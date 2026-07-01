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
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";
import { displayName } from "@/lib/userDisplay";
import type { Elevation } from "@/lib/elevation";

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
  /** Surface elevation; 0 = inline (flush in a table cell). */
  elevation?: Elevation;
  /** Extra classes on the chips container — e.g. `flex-col` to stack the
   *  assignees as a vertical list (used in the task detail drawer). */
  chipsClassName?: string;
}

const AssigneesCombobox = ({
  value,
  options,
  onChange,
  subjectLabel,
  className,
  onAvatarClick,
  elevation = 1,
  chipsClassName,
}: AssigneesComboboxProps) => {
  const handleChange = (next: AssigneeOption[]) => {
    void onChange(next.map((u) => u["@id"]));
  };
  // Anchor the dropdown to the chips field so it lines up (same left edge +
  // width) instead of defaulting to a slightly wider popup.
  const anchor = useComboboxAnchor();
  // Control open so clicking anywhere on the field — even when it already has
  // assignees (the chip can fill the field) — opens the list to add more.
  const [open, setOpen] = useState(false);

  // Always a combobox: assignees render as avatar + name chips and the input
  // carries the placeholder prompt, rather than swapping a collapsed avatar
  // stack for an editor on click — matching the tags field.
  return (
    <div className={cn("group/field relative", className)}>
      <Combobox<AssigneeOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        open={open}
        onOpenChange={setOpen}
        itemToStringLabel={(u) => displayName(u)}
        itemToStringValue={(u) => u["@id"]}
      >
        <ComboboxChips
          ref={anchor}
          // Clicking the field (anywhere but a chip's remove button) opens the
          // list so you can add more people to an already-assigned task.
          onClick={(e) => {
            if (
              !(e.target as HTMLElement).closest(
                '[data-slot="combobox-chip-remove"]',
              )
            ) {
              setOpen(true);
            }
          }}
          elevation={elevation}
          // In the list view (inline elevation) keep the chip + search input on
          // one line so the assignee stays vertically centered in the row;
          // wrapping pushed the chip onto a top line and inflated the row.
          className={cn(
            elevation === 0 && "flex-nowrap group-hover/field:border-input",
            chipsClassName,
          )}
        >
          {value.length === 0 && (
            <AssigneePlaceholder className="group-hover/field:border-foreground/40 group-hover/field:text-foreground" />
          )}
          <ComboboxValue>
            {value.map((u) => (
              <ComboboxChip
                key={u["@id"]}
                aria-label={displayName(u)}
                // In the list view, inherit the row's due-status tint
                // (amber/red) instead of forcing the default text color.
                className={
                  elevation === 0 ? "gap-1.5 px-2 text-inherit" : "gap-1.5 px-2"
                }
                // Inline uses one field-level clear X on the right (below)
                // instead of a per-chip remove, matching the date field.
                showRemove={elevation !== 0}
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
                    className="rounded-md border-0 bg-transparent p-0"
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
        <ComboboxContent anchor={anchor}>
          <ComboboxEmpty>No assignable users.</ComboboxEmpty>
          <ComboboxList>
            {(u: AssigneeOption) => (
              <ComboboxItem key={u["@id"]} value={u}>
                <UserAvatar user={u} size="sm" className="h-5 w-5" />
                <span className="min-w-0 flex-1 truncate">{displayName(u)}</span>
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
            void onChange([]);
          }}
          aria-label="Clear assignees"
          className="absolute right-1 top-1/2 z-10 -translate-y-1/2 rounded p-0.5 text-muted-foreground opacity-0 transition-opacity hover:text-foreground group-hover/field:opacity-100 focus-visible:opacity-100"
          data-testid="task-assignees-clear"
        >
          <X className="size-3.5" />
        </button>
      )}
    </div>
  );
};

export default AssigneesCombobox;
