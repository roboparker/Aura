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
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
import { cn } from "@/lib/utils";

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
  /** When provided, an avatar click invokes this callback with the user IRI. */
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
  // Edit mode: collapsed shows a stacked avatar group plus an "Assign"/"Edit"
  // affordance. Expanded swaps in the full Combobox chrome (chips + popover)
  // for searching the assignable user list.
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

  // Focus the chip-input as soon as we enter edit mode.
  useEffect(() => {
    if (!isEditing) return;
    const input = containerRef.current?.querySelector<HTMLInputElement>(
      '[data-slot="combobox-chip-input"]',
    );
    input?.focus();
  }, [isEditing]);

  const handleChange = (next: AssigneeOption[]) => {
    void onChange(next.map((u) => u["@id"]));
  };

  return (
    <div ref={containerRef} className={className}>
      <Combobox<AssigneeOption, true>
        items={options}
        multiple
        value={value}
        onValueChange={handleChange}
        itemToStringLabel={(u) => displayName(u)}
        itemToStringValue={(u) => u["@id"]}
      >
        <ComboboxChips
          className={cn(
            !isEditing &&
              "min-h-0 border-transparent bg-transparent px-0 py-0 shadow-none focus-within:border-transparent focus-within:ring-0 dark:bg-transparent has-data-[slot=combobox-chip]:px-0",
          )}
        >
          {isEditing ? (
            <>
              <ComboboxValue>
                {value.map((u) => (
                  <ComboboxChip
                    key={u["@id"]}
                    aria-label={displayName(u)}
                    className="gap-1 px-2"
                    data-testid="task-assignee-chip"
                  >
                    <UserAvatar user={u} size="sm" className="h-4 w-4" />
                    <span className="truncate max-w-[10rem]">{displayName(u)}</span>
                  </ComboboxChip>
                ))}
              </ComboboxValue>
              <ComboboxChipsInput
                placeholder={value.length === 0 ? "Assign people…" : ""}
                aria-label={
                  subjectLabel ? `Assignees for "${subjectLabel}"` : "Assignees"
                }
                onKeyDown={(event) => {
                  if (event.key === "Escape") {
                    event.preventDefault();
                    setIsEditing(false);
                  }
                }}
                autoComplete="nope"
                autoCorrect="off"
                autoCapitalize="off"
                spellCheck={false}
                name="aura-assignee-search"
                data-form-type="other"
                data-lpignore="true"
                data-1p-ignore="true"
              />
            </>
          ) : (
            <>
              {/* Collapsed view: stacked avatar group (-space-x makes them overlap)
                  plus an Edit / Assign trigger. Avatars are buttons when
                  onAvatarClick is wired so the user can filter by clicking. */}
              {value.length > 0 && (
                <div
                  className="flex -space-x-1.5 mr-1"
                  data-testid="task-assignees"
                >
                  {value.map((u) =>
                    onAvatarClick ? (
                      <button
                        key={u["@id"]}
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation();
                          onAvatarClick(u);
                        }}
                        aria-label={`Filter by ${displayName(u)}`}
                        title={displayName(u)}
                        className="rounded-full ring-2 ring-background hover:ring-foreground/20 transition cursor-pointer bg-transparent border-0 p-0"
                      >
                        <UserAvatar user={u} size="sm" />
                      </button>
                    ) : (
                      <span
                        key={u["@id"]}
                        title={displayName(u)}
                        className="ring-2 ring-background rounded-full"
                      >
                        <UserAvatar user={u} size="sm" />
                      </span>
                    ),
                  )}
                </div>
              )}
              <button
                type="button"
                onClick={() => setIsEditing(true)}
                aria-label={
                  subjectLabel
                    ? `Edit assignees for "${subjectLabel}"`
                    : value.length === 0
                      ? "Assign people"
                      : "Edit assignees"
                }
                className={cn(
                  "inline-flex items-center gap-1 rounded-sm px-1.5 py-0.5 text-xs",
                  value.length === 0
                    ? "italic text-muted-foreground/60 hover:text-muted-foreground"
                    : "text-muted-foreground/60 hover:text-foreground",
                )}
                data-testid="task-assignees-edit"
              >
                {value.length === 0 ? (
                  <>
                    <Plus className="h-3 w-3" />
                    Assign
                  </>
                ) : (
                  <Pencil className="h-3 w-3" />
                )}
              </button>
            </>
          )}
        </ComboboxChips>
        <ComboboxContent>
          <ComboboxEmpty>No assignable users.</ComboboxEmpty>
          <ComboboxList>
            {(u: AssigneeOption) => (
              <ComboboxItem key={u["@id"]} value={u}>
                <UserAvatar user={u} size="sm" className="h-5 w-5" />
                <span className="flex-1 truncate">{displayName(u)}</span>
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
