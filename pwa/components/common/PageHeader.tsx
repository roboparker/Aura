import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * Shared page header for top-level routes. Standardises the visual
 * rhythm at the top of every page: an optional leading icon, the title,
 * an optional count badge, an optional subtitle, an optional right-side
 * action cluster (e.g. a "New X" button), an optional toolbar row
 * (search bar / filters) below, a divider, and a comfortable gap before
 * the page body.
 *
 * Use it instead of hand-rolling `<h1 className="…">` blocks so the
 * spacing stays consistent — when we tweak the rhythm here, every
 * page picks up the change.
 *
 * Usage:
 *   <PageHeader
 *     title="Tags"
 *     subtitle="Shared across everyone in the space."
 *     icon={<TagIcon className="h-6 w-6 text-cyan-600" />}
 *     count={tags.length}
 *     actions={<Button>Add tag</Button>}
 *   >
 *     <SearchInput … />
 *   </PageHeader>
 */
interface Props {
  title: ReactNode;
  subtitle?: ReactNode;
  /** Icon rendered immediately left of the title. Size it yourself
   *  (e.g. `className="h-6 w-6"`). */
  icon?: ReactNode;
  /** Count badge rendered right of the title (skipped when null/undefined). */
  count?: number | null;
  /** Right-aligned action cluster. Buttons baseline-align with the
   *  bottom of the subtitle. */
  actions?: ReactNode;
  /** Toolbar row (search bar, filters) rendered full-width below the
   *  title/actions row, above the divider. */
  children?: ReactNode;
  /** Extra classes appended to the outer wrapper for one-off tweaks
   *  (e.g. tighter top padding on a nested view). Avoid when you can. */
  className?: string;
}

const PageHeader = ({
  title,
  subtitle,
  icon,
  count,
  actions,
  children,
  className,
}: Props) => {
  return (
    <div className={cn("mb-8 border-b pb-6", className)}>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            {icon}
            <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
            {count !== null && count !== undefined && (
              <span
                className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                data-testid="page-header-count"
              >
                {count}
              </span>
            )}
          </div>
          {subtitle && (
            <p className="mt-2 text-sm text-muted-foreground">{subtitle}</p>
          )}
        </div>
        {actions && (
          <div className="flex items-center gap-2 shrink-0">{actions}</div>
        )}
      </div>
      {children && <div className="mt-4">{children}</div>}
    </div>
  );
};

export default PageHeader;
