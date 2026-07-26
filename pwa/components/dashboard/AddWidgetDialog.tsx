import { useQuery } from "@tanstack/react-query";
import { Plus } from "lucide-react";
import { apiGet } from "@/lib/apiClient";
import { groupCatalog, type WidgetCatalogEntry } from "@/lib/dashboardTypes";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { rendererFor } from "./widgets/registry";

interface Props {
  open: boolean;
  spaceId: string | null;
  adding: string | null;
  onAdd: (entry: WidgetCatalogEntry) => void;
  onOpenChange: (open: boolean) => void;
}

/**
 * The widget picker, built entirely from the server's catalog — it lists
 * whatever the API offers, grouped by section, with no hardcoded knowledge of
 * which widgets exist.
 *
 * The catalog arrives already filtered to what the caller can read, so this
 * can't offer something that would render as a permanently empty box.
 */
const AddWidgetDialog = ({ open, spaceId, adding, onAdd, onOpenChange }: Props) => {
  const query = useQuery({
    queryKey: ["dashboard_catalog", spaceId],
    enabled: open && !!spaceId,
    queryFn: () =>
      apiGet<{ widgets: WidgetCatalogEntry[] }>(
        `/spaces/${spaceId}/dashboard/catalog`,
        { errorMessage: "Failed to load the widget catalog." },
      ),
  });

  const groups = groupCatalog(query.data?.widgets ?? []);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Add a widget</DialogTitle>
        </DialogHeader>

        {query.isLoading ? (
          <p className="py-8 text-center text-sm text-muted-foreground">Loading…</p>
        ) : groups.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">
            No widgets are available to you in this space.
          </p>
        ) : (
          <div className="space-y-5">
            {groups.map(({ group, entries }) => (
              <div key={group}>
                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {group}
                </p>
                <div className="space-y-2">
                  {entries.map((entry) => {
                    const Icon = rendererFor(entry.type)?.icon ?? Plus;
                    return (
                      <button
                        key={entry.type}
                        type="button"
                        data-testid={`add-widget-${entry.type}`}
                        disabled={adding !== null}
                        onClick={() => onAdd(entry)}
                        className="flex w-full items-start gap-3 rounded-md border p-3 text-left transition-colors hover:bg-accent disabled:opacity-60"
                      >
                        <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0">
                          <span className="block text-sm font-medium">
                            {entry.name}
                            {adding === entry.type && " — adding…"}
                          </span>
                          <span className="block text-xs text-muted-foreground">
                            {entry.description}
                          </span>
                        </span>
                      </button>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        )}

        <div className="flex justify-end">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Done
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
};

export default AddWidgetDialog;
