import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/**
 * Shared page header for top-level routes. Standardises the visual
 * rhythm at the top of every page: title, optional subtitle, optional
 * right-side action cluster, a divider, and a comfortable gap before
 * the page body.
 *
 * Use it instead of hand-rolling `<h1 className="…">` blocks so the
 * spacing stays consistent — when we tweak the rhythm here, every
 * page picks up the change.
 *
 * Usage:
 *   <PageHeader
 *     title="Spaces"
 *     subtitle="Workspaces and shared rooms you belong to."
 *     actions={<><Button>Filter</Button>…</>}
 *   />
 */
interface Props {
  title: ReactNode;
  subtitle?: ReactNode;
  /** Right-aligned action cluster. Buttons baseline-align with the
   *  bottom of the subtitle. */
  actions?: ReactNode;
  /** Extra classes appended to the outer wrapper for one-off tweaks
   *  (e.g. tighter top padding on a nested view). Avoid when you can. */
  className?: string;
}

const PageHeader = ({ title, subtitle, actions, className }: Props) => {
  return (
    <div
      className={cn(
        "flex flex-wrap items-end justify-between gap-4 pb-8 mb-8 border-b",
        className,
      )}
    >
      <div>
        <h1 className="text-3xl font-bold tracking-tight">{title}</h1>
        {subtitle && (
          <p className="mt-2 text-sm text-muted-foreground">{subtitle}</p>
        )}
      </div>
      {actions && (
        <div className="flex items-center gap-2 shrink-0">{actions}</div>
      )}
    </div>
  );
};

export default PageHeader;
