import { Check } from "lucide-react";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import AssigneePlaceholder from "@/components/user/AssigneePlaceholder";
import { displayName } from "@/lib/userDisplay";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/** A user carrying its IRI, so the menu can toggle membership + emit IRIs. */
export type AssignableUser = AvatarUser & { "@id": string };

/**
 * Assignee cluster + picker (#space-roles board/calendar): shows the assignees'
 * avatars, or an anonymous {@see AssigneePlaceholder} when no one is assigned;
 * either opens a dropdown to toggle who's assigned. Stops pointer/click
 * propagation so it neither starts a drag nor opens the parent (card/chip).
 * Place it inside a non-button container (a `<div role="button">`).
 */
const AssignMenu = ({
  assignees,
  assignableUsers,
  onAssign,
  align = "end",
}: {
  assignees: AssignableUser[];
  assignableUsers: AssignableUser[];
  onAssign: (userIris: string[]) => void | Promise<void>;
  align?: "start" | "end";
}) => {
  const assignedIris = new Set(assignees.map((a) => a["@id"]));
  const toggle = (iri: string) => {
    const next = assignedIris.has(iri)
      ? assignees.map((a) => a["@id"]).filter((i) => i !== iri)
      : [...assignees.map((a) => a["@id"]), iri];
    void onAssign(next);
  };
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          onClick={(e) => e.stopPropagation()}
          onPointerDown={(e) => e.stopPropagation()}
          className="flex items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label={assignees.length > 0 ? "Change assignees" : "Assign someone"}
          data-testid="assign-menu"
        >
          {assignees.length > 0 ? (
            <span className="flex -space-x-1.5">
              {assignees.slice(0, 3).map((a) => (
                <UserAvatar
                  key={a["@id"]}
                  user={a}
                  size="sm"
                  className="ring-2 ring-card"
                />
              ))}
              {assignees.length > 3 && (
                <span
                  className="inline-flex h-8 w-8 items-center justify-center rounded-md bg-muted text-xs font-medium text-muted-foreground ring-2 ring-card"
                  title={`${assignees.length - 3} more`}
                >
                  +{assignees.length - 3}
                </span>
              )}
            </span>
          ) : (
            <AssigneePlaceholder className="hover:border-foreground/40 hover:text-foreground" />
          )}
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align={align}
        className="max-h-64 overflow-y-auto"
        onClick={(e) => e.stopPropagation()}
      >
        {assignableUsers.length === 0 ? (
          <DropdownMenuItem disabled>No one to assign</DropdownMenuItem>
        ) : (
          assignableUsers.map((u) => (
            <DropdownMenuItem
              key={u["@id"]}
              onSelect={(e) => {
                e.preventDefault();
                toggle(u["@id"]);
              }}
              className="gap-2"
            >
              <UserAvatar user={u} size="sm" />
              <span className="min-w-0 flex-1 truncate">{displayName(u)}</span>
              {assignedIris.has(u["@id"]) && (
                <Check className="h-3.5 w-3.5 shrink-0" />
              )}
            </DropdownMenuItem>
          ))
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default AssignMenu;
