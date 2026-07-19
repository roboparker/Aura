import UserAvatar from "@/components/user/UserAvatar";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { type Task } from "@/components/tasks/taskHelpers";

/**
 * Read-only "board" view for the cross-board /tasks page: one column per
 * board the tasks belong to (plus a "No board" column for standalone
 * tasks). Cards open the detail drawer on click. Deliberately shows NO custom
 * fields — those live only in the detail pane — and cards aren't draggable
 * (a task can't change board from here).
 */
interface TasksBoardBoardProps {
  tasks: Task[];
  /** Board IRI → title, for the column headers. */
  boardTitles: Map<string, string>;
  onOpen: (task: Task) => void;
}

const NO_BOARD = "__none__";

const dueLabel = (iso: string): string => {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
};

const TasksBoardView = ({
  tasks,
  boardTitles,
  onOpen,
}: TasksBoardBoardProps) => {
  // Group tasks by board IRI (or the sentinel for standalone tasks).
  const groups = new Map<string, Task[]>();
  for (const task of tasks) {
    const key = task.board ?? NO_BOARD;
    const list = groups.get(key) ?? [];
    list.push(task);
    groups.set(key, list);
  }

  // Real boards first (alphabetical by title), "No board" last.
  const columns = Array.from(groups.keys())
    .filter((k) => k !== NO_BOARD)
    .sort((a, b) =>
      (boardTitles.get(a) ?? a).localeCompare(boardTitles.get(b) ?? b),
    );
  if (groups.has(NO_BOARD)) columns.push(NO_BOARD);

  const columnTitle = (key: string): string =>
    key === NO_BOARD ? "No board" : boardTitles.get(key) ?? "Board";

  return (
    <div
      className="flex min-h-[calc(100vh-16rem)] items-stretch gap-4 overflow-x-auto px-0.5 pt-1 pb-2"
      data-testid="tasks-board-board"
    >
      {columns.map((key) => {
        const colTasks = (groups.get(key) ?? [])
          .slice()
          .sort((a, b) => {
            if (!a.dueDate && !b.dueDate) return a.title.localeCompare(b.title);
            if (!a.dueDate) return 1;
            if (!b.dueDate) return -1;
            return a.dueDate.localeCompare(b.dueDate);
          });
        return (
          <div
            key={key}
            className="flex w-80 shrink-0 flex-col overflow-hidden rounded-xl border bg-muted/40"
            data-testid="tasks-board-column"
          >
            <div className="flex items-center gap-2 border-b bg-card px-3 py-2">
              <span className="truncate text-base font-bold tracking-tight text-foreground">
                {columnTitle(key)}
              </span>
              <span className="ml-auto rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground">
                {colTasks.length}
              </span>
            </div>
            <div className="flex min-h-24 flex-1 flex-col gap-2 p-2">
              {colTasks.map((task) => (
                <button
                  key={task["@id"]}
                  type="button"
                  onClick={() => onOpen(task)}
                  className="w-full rounded-lg border bg-card p-3.5 text-left shadow-sm transition hover:border-foreground/20"
                  data-testid="tasks-board-card"
                >
                  <div className="space-y-2">
                    <p
                      className={cn(
                        "text-sm leading-snug",
                        task.completedOn && "text-muted-foreground line-through",
                      )}
                    >
                      {task.title}
                    </p>
                    <div className="flex flex-wrap items-center gap-1">
                      {task.dueDate && (
                        <Badge
                          variant="secondary"
                          className="px-1.5 py-0 text-xs font-normal"
                        >
                          {dueLabel(task.dueDate)}
                        </Badge>
                      )}
                      {task.tags.map((tag) => (
                        <Badge
                          key={tag["@id"]}
                          variant="outline"
                          className="px-1.5 py-0 text-xs font-normal"
                        >
                          {tag.title}
                        </Badge>
                      ))}
                      {task.assignees.length > 0 && (
                        <span className="ml-auto flex -space-x-1.5">
                          {task.assignees.slice(0, 3).map((a) => (
                            <UserAvatar
                              key={a["@id"]}
                              user={a}
                              size="sm"
                              className="ring-2 ring-card"
                            />
                          ))}
                        </span>
                      )}
                    </div>
                  </div>
                </button>
              ))}
              {colTasks.length === 0 && (
                <p className="px-1.5 py-1 text-xs text-muted-foreground">
                  No tasks.
                </p>
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
};

export default TasksBoardView;
