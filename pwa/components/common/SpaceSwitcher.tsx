import Link from "next/link";
import { useRouter } from "next/router";
import { ChevronDown, FolderPlus, LayoutGrid } from "lucide-react";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { cn } from "@/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import SpaceTile from "@/components/spaces/SpaceTile";

/**
 * Active-space switcher rendered at the top of the sidebar nav (under
 * the user header, above the personal links). The trigger shows the
 * tile + name of the currently active space; the dropdown lists
 * recent spaces, with a divider above shortcuts to the full grid
 * (/spaces) and the create page (/spaces/new).
 *
 * Active-row styling deliberately drops the rounded corners and the
 * checkmark — it carries a flat left accent strip instead, so the
 * selected entry feels like a tab anchored to the menu's edge rather
 * than a pill floating inside it.
 */
const SpaceSwitcher = () => {
  const { spaces, activeSpace, setActiveSpace, isLoading } = useActiveSpace();
  const router = useRouter();

  if (isLoading && spaces.length === 0) {
    return (
      <div className="px-2 text-xs text-muted-foreground">Loading…</div>
    );
  }

  if (!activeSpace) {
    return null;
  }

  // Sort by recency for the dropdown — the spec called for "most
  // recent spaces" and the API doesn't already order by updatedAt.
  const sorted = [...spaces].sort(
    (a, b) =>
      new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime(),
  );

  // Cap the inline list at the 5 most recent spaces; the rest live
  // behind the "All spaces" link below. Always keep the active space in
  // view so its selected accent strip stays meaningful even when it's
  // not among the most recently touched.
  const RECENT_LIMIT = 5;
  const recent = sorted.slice(0, RECENT_LIMIT);
  if (activeSpace && !recent.some((s) => s.id === activeSpace.id)) {
    recent.pop();
    recent.unshift(activeSpace);
  }
  const hiddenCount = spaces.length - recent.length;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          type="button"
          data-testid="space-switcher"
          aria-label={`Active space: ${activeSpace.name}`}
          className={cn(
            "w-full flex items-center gap-2 rounded-md border bg-background px-2 py-1.5 text-sm",
            "hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring",
          )}
        >
          <SpaceTile
            name={activeSpace.name}
            color={resolveSpaceColor(activeSpace)}
            isPersonal={activeSpace.isPersonal}
            size="sm"
          />
          <span className="truncate flex-1 text-left font-medium">
            {activeSpace.name}
          </span>
          <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="min-w-[240px] p-1">
        {recent.map((space) => {
          const isActive = space.id === activeSpace.id;
          return (
            <DropdownMenuItem
              key={space["@id"]}
              onSelect={() => {
                setActiveSpace(space);
                // Navigate to the detail page so picking a space
                // takes you to its content — not just a silent
                // active-space swap behind the scenes.
                void router.push(`/spaces/${space.id}`);
              }}
              data-testid="space-switcher-option"
              data-active={isActive ? "true" : undefined}
              className={cn(
                "gap-2 cursor-pointer",
                // Active row: drop the dropdown-item rounding on the
                // left edge and paint a thick accent strip there
                // instead. No checkmark, no count — the strip is the
                // signal.
                isActive
                  ? "rounded-l-none border-l-[3px] border-l-emerald-500 pl-[calc(0.5rem-3px)]"
                  : "",
              )}
            >
              <SpaceTile
                name={space.name}
                color={resolveSpaceColor(space)}
                isPersonal={space.isPersonal}
                size="sm"
              />
              <span className="truncate flex-1">{space.name}</span>
            </DropdownMenuItem>
          );
        })}
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link href="/spaces" className="gap-2 cursor-pointer">
            <LayoutGrid className="h-3.5 w-3.5" aria-hidden />
            {hiddenCount > 0 ? `See all spaces (${spaces.length})` : "All spaces"}
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem asChild>
          <Link href="/spaces/new" className="gap-2 cursor-pointer">
            <FolderPlus className="h-3.5 w-3.5" aria-hidden />
            Create space
          </Link>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default SpaceSwitcher;
