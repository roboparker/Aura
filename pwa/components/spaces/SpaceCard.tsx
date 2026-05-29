import Link from "next/link";
import { FileText, FolderKanban, Lock, LockOpen, Users } from "lucide-react";
import type { Space } from "@/contexts/ActiveSpaceContext";
import { resolveSpaceColor } from "@/lib/avatarPalette";
import { formatRelative } from "@/lib/relativeTime";
import { cn } from "@/lib/utils";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import SpaceTile from "./SpaceTile";

/**
 * Card for the spaces grid (`/spaces`). Renders the colored tile, the
 * space name with a visibility badge (personal/shared) and the user's
 * role, the description, and a footer with member / project / page
 * counts and the relative "updated X ago" timestamp.
 *
 * The whole card is a Link to the space detail; interactive children
 * (none today, but room for menus tomorrow) would need
 * `onClick={(e) => e.stopPropagation()}` to avoid following the
 * outer href.
 */
interface Props {
  space: Space;
  /** Role of the current user inside this space — drives the badge. */
  role: "admin" | "member" | null;
  /** Combined direct + group-inherited member count used in the
   *  footer. Computed in the parent so the grid stays a pure
   *  presentation layer. */
  effectiveMemberCount: number;
}

const memberLabel = (n: number) => `${n} ${n === 1 ? "member" : "members"}`;
const projectLabel = (n: number) =>
  `${n} ${n === 1 ? "project" : "projects"}`;
const pageLabel = (n: number) => `${n} ${n === 1 ? "page" : "pages"}`;

const SpaceCard = ({ space, role, effectiveMemberCount }: Props) => {
  return (
    <Link
      href={`/spaces/${space.id}`}
      className={cn(
        "no-underline group block focus:outline-none",
        "focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        "rounded-lg",
      )}
    >
      <Card className="p-4 transition-colors hover:border-foreground/20">
        <div className="flex items-start gap-3">
          <SpaceTile
            name={space.name}
            color={resolveSpaceColor(space)}
            isPersonal={space.isPersonal}
            size="md"
          />
          <div className="min-w-0 flex-1">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0 flex items-center gap-2 flex-wrap">
                <h3 className="font-semibold truncate">{space.name}</h3>
                {space.isPersonal ? (
                  <Badge
                    variant="outline"
                    className="font-normal text-muted-foreground gap-1"
                  >
                    <Lock className="h-3 w-3" aria-hidden />
                    personal
                  </Badge>
                ) : (
                  <Badge
                    variant="outline"
                    className="font-normal text-muted-foreground gap-1"
                  >
                    <LockOpen className="h-3 w-3" aria-hidden />
                    shared
                  </Badge>
                )}
              </div>
              {role && (
                <Badge
                  variant="outline"
                  className={cn(
                    "font-normal shrink-0",
                    role === "admin"
                      ? "border-violet-500/30 text-violet-600 dark:text-violet-400"
                      : "border-emerald-500/30 text-emerald-600 dark:text-emerald-400",
                  )}
                >
                  • {role}
                </Badge>
              )}
            </div>
            {space.description && (
              <p className="mt-1 text-sm text-muted-foreground line-clamp-2">
                {space.description}
              </p>
            )}
          </div>
        </div>

        <div className="mt-3 flex items-center gap-4 text-xs text-muted-foreground">
          {space.isPersonal ? (
            <span className="inline-flex items-center gap-1">
              <Lock className="h-3 w-3" aria-hidden />
              only you
            </span>
          ) : (
            <span className="inline-flex items-center gap-1">
              <Users className="h-3 w-3" aria-hidden />
              {memberLabel(effectiveMemberCount)}
            </span>
          )}
          <span className="inline-flex items-center gap-1">
            <FolderKanban className="h-3 w-3" aria-hidden />
            {projectLabel(space.projectsCount)}
          </span>
          <span className="inline-flex items-center gap-1">
            <FileText className="h-3 w-3" aria-hidden />
            {pageLabel(space.pagesCount)}
          </span>
          <span className="ml-auto">
            updated {formatRelative(space.updatedAt)}
          </span>
        </div>
      </Card>
    </Link>
  );
};

export default SpaceCard;
