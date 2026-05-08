import Link from "next/link";
import { ChevronDown, Lock, Settings } from "lucide-react";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * Active-space dropdown surfaced next to the Aura wordmark in the
 * navbar. Picks the user's current space and persists the choice via
 * ActiveSpaceContext so listing pages (`/projects`, `/discussions`)
 * scope to it.
 *
 * The trigger is always visible when there's at least one space
 * loaded; until then it falls through to "Loading…" so the navbar
 * doesn't flicker. Personal spaces wear the lock icon to make them
 * easy to spot in a list of mostly-shared rows.
 */
const SpaceSwitcher = () => {
  const { spaces, activeSpace, setActiveSpace, isLoading } = useActiveSpace();

  if (isLoading && spaces.length === 0) {
    return (
      <span className="px-2 text-xs text-muted-foreground">Loading…</span>
    );
  }

  if (!activeSpace) {
    return null;
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="gap-1 max-w-[200px]"
          data-testid="space-switcher"
          aria-label={`Active space: ${activeSpace.name}`}
        >
          {activeSpace.isPersonal && (
            <Lock className="h-3.5 w-3.5 shrink-0" aria-hidden />
          )}
          <span className="truncate">{activeSpace.name}</span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0" aria-hidden />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="min-w-[220px]">
        <DropdownMenuLabel>Switch space</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {spaces.map((space) => (
          <DropdownMenuItem
            key={space["@id"]}
            onSelect={() => setActiveSpace(space)}
            data-testid="space-switcher-option"
            data-active={space.id === activeSpace.id ? "true" : undefined}
            className="gap-2"
          >
            {space.isPersonal && (
              <Lock className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
            )}
            <span className="truncate flex-1">{space.name}</span>
            {space.id === activeSpace.id && (
              <span className="text-xs text-muted-foreground">Active</span>
            )}
          </DropdownMenuItem>
        ))}
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link href="/spaces" className="gap-2">
            <Settings className="h-3.5 w-3.5" aria-hidden />
            Manage spaces
          </Link>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default SpaceSwitcher;
