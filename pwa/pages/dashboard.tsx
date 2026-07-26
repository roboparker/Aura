import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  DndContext,
  KeyboardSensor,
  PointerSensor,
  closestCenter,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core";
import {
  SortableContext,
  arrayMove,
  rectSortingStrategy,
  sortableKeyboardCoordinates,
} from "@dnd-kit/sortable";
import { LayoutDashboard, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  defaultConfigFor,
  type DashboardResponse,
  type DashboardWidget,
  type WidgetCatalogEntry,
} from "@/lib/dashboardTypes";
import PageHeader from "@/components/common/PageHeader";
import AddWidgetDialog from "@/components/dashboard/AddWidgetDialog";
import WidgetConfigSheet from "@/components/dashboard/WidgetConfigSheet";
import WidgetFrame from "@/components/dashboard/WidgetFrame";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";

/**
 * The configurable space dashboard (#759).
 *
 * The layout is shared by the space: admins arrange it, everyone else reads it.
 * Widgets render from whatever the server sends — this page has no knowledge of
 * any particular widget type, which is what lets another module add one without
 * touching it.
 */
const DashboardPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [picking, setPicking] = useState(false);
  const [adding, setAdding] = useState<string | null>(null);
  const [configuring, setConfiguring] = useState<DashboardWidget | null>(null);
  const [saving, setSaving] = useState(false);
  const [configError, setConfigError] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  // Local copy so a drag reorders instantly instead of waiting on the server.
  const [order, setOrder] = useState<DashboardWidget[] | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceId = activeSpace?.id ?? null;

  const query = useQuery({
    queryKey: ["dashboard", spaceId],
    enabled: isAuthenticated && !!spaceId,
    queryFn: () =>
      apiGet<DashboardResponse>(`/spaces/${spaceId}/dashboard`, {
        errorMessage: "Failed to load the dashboard.",
      }),
  });

  // Server order wins on every refetch; local order only covers the gap between
  // dropping a widget and the reorder round-tripping.
  useEffect(() => {
    if (query.data) setOrder(query.data.widgets);
  }, [query.data]);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const widgets = order ?? [];
  const canEdit = query.data?.canEdit ?? false;

  const refresh = () => queryClient.invalidateQueries({ queryKey: ["dashboard", spaceId] });

  const handleDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id || !spaceId) return;

    const from = widgets.findIndex((w) => w.id === active.id);
    const to = widgets.findIndex((w) => w.id === over.id);
    if (from < 0 || to < 0) return;

    const next = arrayMove(widgets, from, to);
    setOrder(next);

    try {
      await apiSend("POST", `/spaces/${spaceId}/dashboard/reorder`, {
        body: { order: next.map((w) => w.id) },
        errorMessage: "Could not save the new order.",
      });
    } catch {
      setError("Could not save the new order.");
      // Snap back rather than leaving the screen disagreeing with the server.
      void refresh();
    }
  };

  const handleAdd = async (entry: WidgetCatalogEntry) => {
    if (!spaceId) return;
    setAdding(entry.type);
    setError(null);
    try {
      await apiSend("POST", `/spaces/${spaceId}/dashboard/widgets`, {
        body: { type: entry.type, config: defaultConfigFor(entry.configSchema) },
        errorMessage: "Could not add that widget.",
      });
      await refresh();
    } catch {
      setError("Could not add that widget.");
    } finally {
      setAdding(null);
    }
  };

  const handleSaveConfig = async (
    config: Record<string, unknown>,
    width: number,
    height: number,
  ) => {
    if (!configuring) return;
    setSaving(true);
    setConfigError(null);
    try {
      await apiSend("PATCH", `/dashboard-widgets/${configuring.id}`, {
        body: { config, width, height },
        contentType: "application/merge-patch+json",
        errorMessage: "Could not save the widget.",
      });
      await refresh();
      setConfiguring(null);
    } catch (e) {
      // The widget definition validates its own config, so this is usually a
      // real message worth showing rather than a generic failure.
      setConfigError(e instanceof Error ? e.message : "Could not save the widget.");
    } finally {
      setSaving(false);
    }
  };

  const handleRemove = async (widget: DashboardWidget) => {
    try {
      await apiSend("DELETE", `/dashboard-widgets/${widget.id}`, {
        errorMessage: "Could not remove that widget.",
      });
      await refresh();
    } catch {
      setError("Could not remove that widget.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Dashboard — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-6xl">
          <PageHeader
            title="Dashboard"
            subtitle={
              canEdit
                ? "Arrange this space's dashboard. Everyone in the space sees this layout."
                : "This space's dashboard, arranged by a space admin."
            }
            icon={<LayoutDashboard className="size-6 text-violet-600 dark:text-violet-400" />}
            actions={
              canEdit ? (
                <Button size="sm" onClick={() => setPicking(true)}>
                  <Plus className="h-4 w-4" /> Add widget
                </Button>
              ) : undefined
            }
          />

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {query.isLoading ? (
            <div className="grid gap-4 sm:grid-cols-4">
              {[0, 1, 2].map((i) => (
                <Card key={i} className="h-[288px] animate-pulse bg-muted/40 sm:col-span-2" />
              ))}
            </div>
          ) : widgets.length === 0 ? (
            <Card className="px-6 py-16 text-center">
              <LayoutDashboard className="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
              <p className="font-medium">This dashboard is empty</p>
              <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {canEdit
                  ? "Add charts, task lists, engagement budgets, or a note. Everyone in this space sees what you arrange here."
                  : "A space admin hasn't added any widgets yet."}
              </p>
              {canEdit && (
                <Button className="mt-4" onClick={() => setPicking(true)}>
                  <Plus className="h-4 w-4" /> Add your first widget
                </Button>
              )}
            </Card>
          ) : (
            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={(e) => void handleDragEnd(e)}
            >
              <SortableContext
                items={widgets.map((w) => w.id)}
                strategy={rectSortingStrategy}
              >
                {/* One column on a phone; the four-column grid only kicks in
                    once there's room, so widget widths stay meaningful. */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                  {widgets.map((widget) => (
                    <WidgetFrame
                      key={widget.id}
                      widget={widget}
                      canEdit={canEdit}
                      onConfigure={setConfiguring}
                      onRemove={(w) => void handleRemove(w)}
                    />
                  ))}
                </div>
              </SortableContext>
            </DndContext>
          )}
        </div>
      </div>

      <AddWidgetDialog
        open={picking}
        spaceId={spaceId}
        adding={adding}
        onAdd={(entry) => void handleAdd(entry)}
        onOpenChange={setPicking}
      />

      <WidgetConfigSheet
        widget={configuring}
        saving={saving}
        error={configError}
        onSave={(config, width, height) => void handleSaveConfig(config, width, height)}
        onClose={() => {
          setConfiguring(null);
          setConfigError(null);
        }}
      />
    </>
  );
};

export default DashboardPage;
