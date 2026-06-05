import { cn } from "@/lib/utils";

export type DiscussionCategory =
  | "general"
  | "ideas"
  | "announcements"
  | "q-and-a";

export interface CategoryMeta {
  label: string;
  /** Solid dot color (e.g. on the pill, filter chips, legend). */
  dot: string;
  /** Pill classes — tinted border + background + text, dual-mode. */
  pill: string;
  /** Foreground tint for icons rendered on a neutral surface. */
  icon: string;
}

/**
 * Per-category visual language for discussions. One source of truth for
 * the filter chips, row badges, detail header, and empty-state legend
 * so a category always reads the same color everywhere. Classes are
 * dual-mode (light/dark) to match the rest of the shadcn token surface.
 */
export const CATEGORY_META: Record<DiscussionCategory, CategoryMeta> = {
  general: {
    label: "General",
    dot: "bg-cyan-500",
    pill: "border-cyan-500/30 bg-cyan-500/10 text-cyan-700 dark:text-cyan-300",
    icon: "text-cyan-600 dark:text-cyan-400",
  },
  ideas: {
    label: "Ideas",
    dot: "bg-violet-500",
    pill: "border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-300",
    icon: "text-violet-600 dark:text-violet-400",
  },
  announcements: {
    label: "Announcements",
    dot: "bg-rose-500",
    pill: "border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-300",
    icon: "text-rose-600 dark:text-rose-400",
  },
  "q-and-a": {
    label: "Q&A",
    dot: "bg-emerald-500",
    pill: "border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
    icon: "text-emerald-600 dark:text-emerald-400",
  },
};

/** Display order used by filter chips and the empty-state legend. */
export const CATEGORY_ORDER: DiscussionCategory[] = [
  "general",
  "ideas",
  "announcements",
  "q-and-a",
];

export const CATEGORY_LABEL: Record<DiscussionCategory, string> = {
  general: CATEGORY_META.general.label,
  ideas: CATEGORY_META.ideas.label,
  announcements: CATEGORY_META.announcements.label,
  "q-and-a": CATEGORY_META["q-and-a"].label,
};

interface Props {
  category: DiscussionCategory;
  className?: string;
}

/**
 * Colored-dot pill naming a discussion's category. Used in list rows
 * and the detail header.
 */
const CategoryBadge = ({ category, className }: Props) => {
  const meta = CATEGORY_META[category];
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium",
        meta.pill,
        className,
      )}
      data-testid="discussion-category-badge"
    >
      <span className={cn("h-1.5 w-1.5 rounded-full", meta.dot)} aria-hidden />
      {meta.label}
    </span>
  );
};

export default CategoryBadge;
