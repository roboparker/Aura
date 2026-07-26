import { useSortable } from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { useQuery } from "@tanstack/react-query";
import { GripVertical, MoreVertical, Pencil, PuzzleIcon, Trash2 } from "lucide-react";
import { apiGet } from "@/lib/apiClient";
import { HEIGHT_PX, WIDTH_CLASS, type DashboardWidget } from "@/lib/dashboardTypes";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { rendererFor } from "./widgets/registry";

interface Props {
  widget: DashboardWidget;
  canEdit: boolean;
  onConfigure: (widget: DashboardWidget) => void;
  onRemove: (widget: DashboardWidget) => void;
}

/**
 * The box around one widget: chrome, drag handle, admin menu, and the widget's
 * own data fetch.
 *
 * **Each frame fetches its own data.** That's what lets widgets load in
 * parallel and keeps one slow query from holding up the page — and it means a
 * new widget type needs nothing here.
 */
const WidgetFrame = ({ widget, canEdit, onConfigure, onRemove }: Props) => {
  const renderer = rendererFor(widget.type);
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id: widget.id, disabled: !canEdit });

  // Config-only widgets (a note) have nothing to fetch — asking anyway would be
  // a wasted round trip per note on the board.
  const shouldFetch = widget.available && renderer?.fetchesData !== false;
  const query = useQuery({
    queryKey: ["dashboard_widget_data", widget.id, widget.config],
    enabled: shouldFetch,
    queryFn: () =>
      apiGet<{ data: unknown }>(`/dashboard-widgets/${widget.id}/data`, {
        errorMessage: "Failed to load this widget.",
      }),
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    minHeight: HEIGHT_PX[widget.height] ?? HEIGHT_PX[2],
  };

  const body = () => {
    // The server has a widget type this client doesn't know: a module removed,
    // or a client older than the API. Say so plainly instead of rendering a
    // blank card or crashing the whole grid.
    if (!widget.available || !renderer) {
      return (
        <div className="flex h-full flex-col items-center justify-center text-center">
          <PuzzleIcon className="mb-2 h-5 w-5 text-muted-foreground" />
          <p className="text-sm font-medium">Widget unavailable</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Nothing here can render <code>{widget.type}</code>.
          </p>
        </div>
      );
    }

    if (query.isError) {
      return (
        <div className="flex h-full items-center justify-center">
          <p className="text-sm text-muted-foreground">Couldn&apos;t load this widget.</p>
        </div>
      );
    }

    const Component = renderer.component;
    return (
      <Component
        widget={widget}
        data={query.data?.data ?? null}
        isLoading={shouldFetch && query.isLoading}
      />
    );
  };

  const Icon = renderer?.icon ?? PuzzleIcon;

  return (
    <div
      ref={setNodeRef}
      style={style}
      data-testid={`widget-${widget.type}`}
      className={`${WIDTH_CLASS[widget.width] ?? WIDTH_CLASS[2]} ${
        isDragging ? "z-10 opacity-70" : ""
      }`}
    >
      <Card className="flex h-full flex-col p-3">
        <div className="mb-2 flex items-center gap-1.5">
          {canEdit && (
            <button
              type="button"
              className="cursor-grab text-muted-foreground hover:text-foreground active:cursor-grabbing"
              aria-label={`Move ${widget.name ?? widget.type}`}
              {...attributes}
              {...listeners}
            >
              <GripVertical className="h-4 w-4" />
            </button>
          )}
          <Icon className="h-4 w-4 text-muted-foreground" />
          <p className="flex-1 truncate text-xs font-medium uppercase tracking-wide text-muted-foreground">
            {widget.name ?? widget.type}
          </p>
          {canEdit && (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="h-6 w-6">
                  <MoreVertical className="h-4 w-4" />
                  <span className="sr-only">Widget options</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {/* An unavailable type has no schema to render an editor from,
                    but it must still be removable — otherwise a stale widget
                    would be stuck on the dashboard forever. */}
                {widget.available && (
                  <DropdownMenuItem onClick={() => onConfigure(widget)}>
                    <Pencil className="mr-2 h-4 w-4" /> Configure
                  </DropdownMenuItem>
                )}
                <DropdownMenuItem
                  className="text-destructive"
                  onClick={() => onRemove(widget)}
                >
                  <Trash2 className="mr-2 h-4 w-4" /> Remove
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )}
        </div>

        <div className="min-h-0 flex-1 overflow-auto">{body()}</div>
      </Card>
    </div>
  );
};

export default WidgetFrame;
