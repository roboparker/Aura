import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import type { WidgetProps } from "./registry";

interface BoardRow {
  id: string;
  title: string;
  openTasks: number;
}

const BoardsWidget = ({ data, isLoading }: WidgetProps) => {
  if (isLoading) {
    return <div className="h-full animate-pulse rounded-md bg-muted/40" />;
  }

  const rows = ((data ?? {}) as { rows?: BoardRow[] }).rows ?? [];
  if (rows.length === 0) {
    return (
      <div className="flex h-full items-center justify-center">
        <p className="text-sm text-muted-foreground">No boards in this space yet.</p>
      </div>
    );
  }

  return (
    <ul className="space-y-2">
      {rows.map((row) => (
        <li key={row.id} className="flex items-center justify-between gap-2">
          <Link
            href={`/boards/${row.id}`}
            className="truncate text-sm hover:underline"
          >
            {row.title}
          </Link>
          <Badge variant="secondary" className="shrink-0 tabular-nums">
            {row.openTasks}
          </Badge>
        </li>
      ))}
    </ul>
  );
};

export default BoardsWidget;
