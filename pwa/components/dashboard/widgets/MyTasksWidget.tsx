import Link from "next/link";
import type { WidgetProps } from "./registry";

interface TaskRow {
  id: string;
  title: string;
  dueDate: string | null;
  overdue: boolean;
  board: string | null;
  boardId: string | null;
}

const dueLabel = (iso: string): string => {
  const date = new Date(`${iso}T00:00:00`);
  return date.toLocaleDateString(undefined, { month: "short", day: "numeric" });
};

const MyTasksWidget = ({ data, isLoading }: WidgetProps) => {
  if (isLoading) {
    return <div className="h-full animate-pulse rounded-md bg-muted/40" />;
  }

  const rows = ((data ?? {}) as { rows?: TaskRow[] }).rows ?? [];
  if (rows.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-sm text-muted-foreground">Nothing open — you&apos;re clear.</p>
      </div>
    );
  }

  return (
    <ul className="space-y-2">
      {rows.map((row) => (
        <li key={row.id} className="flex items-baseline justify-between gap-2">
          <Link
            href={`/tasks?task=${encodeURIComponent(row.id)}`}
            className="truncate text-sm hover:underline"
          >
            {row.title}
          </Link>
          {row.dueDate && (
            <span
              className={`shrink-0 text-xs tabular-nums ${
                row.overdue
                  ? "font-medium text-red-600 dark:text-red-400"
                  : "text-muted-foreground"
              }`}
            >
              {dueLabel(row.dueDate)}
            </span>
          )}
        </li>
      ))}
    </ul>
  );
};

export default MyTasksWidget;
