import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import { TableHead } from "@/components/ui/table";
import { cn } from "@/lib/utils";
import type { SortKey, SortState } from "@/components/tasks/taskHelpers";

interface SortableHeaderProps {
  label: string;
  sortKey: SortKey;
  active: SortState;
  onSort: (key: SortKey) => void;
  className?: string;
}

const SortableHeader = ({ label, sortKey, active, onSort, className }: SortableHeaderProps) => {
  const isActive = active.key === sortKey;
  const Icon = isActive ? (active.dir === "asc" ? ArrowUp : ArrowDown) : ArrowUpDown;
  return (
    <TableHead className={className}>
      <button
        type="button"
        onClick={() => onSort(sortKey)}
        className="inline-flex items-center gap-1 -ml-2 px-2 py-1 rounded-sm hover:bg-accent text-left font-medium"
        aria-sort={
          isActive ? (active.dir === "asc" ? "ascending" : "descending") : "none"
        }
      >
        <span>{label}</span>
        <Icon className={cn("h-3.5 w-3.5", isActive ? "opacity-100" : "opacity-50")} />
      </button>
    </TableHead>
  );
};

export default SortableHeader;
