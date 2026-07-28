import dynamic from "next/dynamic";
import { useEffect, useMemo, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Workflow } from "lucide-react";
import { apiGet, apiSend } from "@/lib/apiClient";
import {
  KIND_HINT,
  KIND_PALETTE_LABEL,
  defaultConfigFor,
  definitionFor,
  describeProblem,
  nextNodeId,
  startingGraph,
  type Automation,
  type AutomationGraph,
  type NodeKind,
  type StepCatalog,
} from "@/lib/automationTypes";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import AutomationRunLog from "./AutomationRunLog";
import StepConfigPanel, { type PickerOption } from "./StepConfigPanel";

// React Flow is heavy and only this tab needs it.
const AutomationCanvas = dynamic(() => import("./AutomationCanvas"), {
  ssr: false,
  // Matches the canvas's own height so the tab doesn't jump when it loads.
  loading: () => (
    <Skeleton className="h-[calc(100vh-20rem)] min-h-[520px] rounded-md bg-muted/40" />
  ),
});

interface Props {
  boardId: string;
  sections: PickerOption[];
  members: PickerOption[];
}

/**
 * The Automations tab on a board (#764).
 *
 * A rule list on the left, the selected rule's canvas on the right. Editing is
 * space-admin — the server decides, and `canEdit` comes back with the list, so
 * a member sees exactly the same rules read-only rather than a locked-out page.
 *
 * **Below `lg` the canvas is read-only.** Drag-and-connect on a phone is bad
 * enough that pretending otherwise is worse than saying so.
 */
const AutomationsTab = ({ boardId, sections, members }: Props) => {
  const queryClient = useQueryClient();
  const [selectedRuleId, setSelectedRuleId] = useState<string | null>(null);
  const [draft, setDraft] = useState<AutomationGraph | null>(null);
  const [draftName, setDraftName] = useState("");
  const [selectedNodeId, setSelectedNodeId] = useState<string | null>(null);
  const [view, setView] = useState<"build" | "history">("build");
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const rulesQuery = useQuery({
    queryKey: ["board_automations", boardId],
    queryFn: () =>
      apiGet<{ canEdit: boolean; automations: Automation[] }>(
        `/boards/${boardId}/automations`,
        { errorMessage: "Failed to load automations." },
      ),
  });

  const catalogQuery = useQuery({
    queryKey: ["board_automation_catalog", boardId],
    queryFn: () =>
      apiGet<StepCatalog>(`/boards/${boardId}/automations/catalog`, {
        errorMessage: "Failed to load the step catalog.",
      }),
  });

  const rules = rulesQuery.data?.automations ?? [];
  const canEdit = rulesQuery.data?.canEdit ?? false;
  const catalog = catalogQuery.data ?? null;

  const selectedRule = rules.find((r) => r.id === selectedRuleId) ?? null;

  // Load the selected rule into the editable draft.
  useEffect(() => {
    if (selectedRule) {
      setDraft(selectedRule.graph);
      setDraftName(selectedRule.name);
      setSelectedNodeId(null);
      // Back to Build on rule change — carrying History over means clicking a
      // rule to edit it lands you on a log instead.
      setView("build");
    }
  }, [selectedRule?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const problem = draft ? describeProblem(draft) : null;
  const selectedNode = draft?.nodes.find((n) => n.id === selectedNodeId) ?? null;

  const refresh = () =>
    queryClient.invalidateQueries({ queryKey: ["board_automations", boardId] });

  const addStep = (kind: NodeKind, type: string) => {
    if (!draft || !catalog) return;
    const definition = definitionFor(catalog, kind, type);
    if (!definition) return;

    const id = nextNodeId(draft, kind);
    setDraft({
      ...draft,
      nodes: [
        ...draft.nodes,
        {
          id,
          kind,
          type,
          config: defaultConfigFor(definition.configSchema),
          // Dropped to the right of everything, so a new step never lands
          // underneath an existing one.
          position: {
            x: 60 + draft.nodes.length * 60,
            y: 60 + draft.nodes.length * 90,
          },
        },
      ],
    });
    setSelectedNodeId(id);
  };

  const createRule = async () => {
    if (!catalog || catalog.triggers.length === 0) return;
    setError(null);
    try {
      const created = await apiSend<Automation>("POST", `/boards/${boardId}/automations`, {
        body: {
          name: "New rule",
          // Starts disabled: a rule that begins running the instant it's
          // created, before it has any actions, is a nasty surprise.
          enabled: false,
          graph: startingGraph(catalog.triggers[0].type),
        },
        errorMessage: "Could not create the rule.",
      });
      await refresh();
      if (created) setSelectedRuleId(created.id);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not create the rule.");
    }
  };

  const saveRule = async () => {
    if (!selectedRule || !draft) return;
    setSaving(true);
    setError(null);
    try {
      await apiSend("PATCH", `/automations/${selectedRule.id}`, {
        body: { name: draftName, graph: draft },
        contentType: "application/merge-patch+json",
        errorMessage: "Could not save the rule.",
      });
      await refresh();
    } catch (e) {
      // The server validates too, and its message names the offending step.
      setError(e instanceof Error ? e.message : "Could not save the rule.");
    } finally {
      setSaving(false);
    }
  };

  const toggleRule = async (rule: Automation, enabled: boolean) => {
    setError(null);
    try {
      await apiSend("PATCH", `/automations/${rule.id}`, {
        body: { enabled },
        contentType: "application/merge-patch+json",
        errorMessage: "Could not change the rule.",
      });
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not change the rule.");
    }
  };

  const deleteRule = async (rule: Automation) => {
    setError(null);
    try {
      await apiSend("DELETE", `/automations/${rule.id}`, {
        errorMessage: "Could not delete the rule.",
      });
      if (selectedRuleId === rule.id) setSelectedRuleId(null);
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not delete the rule.");
    }
  };

  const palette = useMemo(
    () =>
      catalog
        ? ([
            ["condition", catalog.conditions],
            ["action", catalog.actions],
          ] as const)
        : [],
    [catalog],
  );

  if (rulesQuery.isLoading) {
    return <Skeleton className="h-64 rounded-md bg-muted/40" />;
  }

  return (
    <div className="space-y-4">
      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
        {/* Rule list */}
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Rules
            </p>
            {canEdit && (
              <Button size="sm" variant="outline" onClick={() => void createRule()}>
                <Plus className="h-4 w-4" /> New
              </Button>
            )}
          </div>

          {rules.length === 0 ? (
            <Card className="px-4 py-8 text-center">
              <Workflow className="mx-auto mb-2 h-6 w-6 text-muted-foreground" />
              <p className="text-sm font-medium">No rules yet</p>
              <p className="mt-1 text-xs text-muted-foreground">
                {canEdit
                  ? "Automate the repetitive parts of this board."
                  : "A space admin hasn't added any."}
              </p>
            </Card>
          ) : (
            <ul className="space-y-1.5">
              {rules.map((rule) => (
                <li key={rule.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedRuleId(rule.id)}
                    data-testid={`rule-${rule.id}`}
                    className={`w-full rounded-md border p-2.5 text-left transition-colors hover:bg-accent ${
                      rule.id === selectedRuleId ? "border-primary bg-accent" : ""
                    }`}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="truncate text-sm font-medium">{rule.name}</span>
                      {!rule.enabled && (
                        <Badge variant="secondary" className="shrink-0 text-[10px]">
                          Off
                        </Badge>
                      )}
                    </div>
                    {rule.lastError && (
                      <p className="mt-1 truncate text-xs text-destructive">
                        {rule.lastError}
                      </p>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>

        {/* Editor */}
        {!selectedRule || !draft ? (
          <Card className="flex min-h-[320px] items-center justify-center px-6 py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {rules.length === 0
                ? "Create a rule to get started."
                : "Pick a rule to see how it works."}
            </p>
          </Card>
        ) : (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <Input
                value={draftName}
                disabled={!canEdit}
                onChange={(e) => setDraftName(e.target.value)}
                className="h-9 max-w-xs"
                aria-label="Rule name"
              />
              <div className="flex items-center gap-2">
                <Switch
                  id="rule-enabled"
                  checked={selectedRule.enabled}
                  disabled={!canEdit}
                  onCheckedChange={(checked) => void toggleRule(selectedRule, checked)}
                />
                <label htmlFor="rule-enabled" className="text-sm">
                  Enabled
                </label>
              </div>
              <div className="ml-auto flex items-center gap-2">
                {canEdit && (
                  <>
                    <Button
                      size="sm"
                      onClick={() => void saveRule()}
                      disabled={saving || problem !== null}
                    >
                      {saving ? "Saving…" : "Save"}
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      className="text-destructive"
                      onClick={() => void deleteRule(selectedRule)}
                    >
                      Delete
                    </Button>
                  </>
                )}
              </div>
            </div>

            {/* Build vs History. The log is a first-class view rather than a
                buried panel — it's what a member opens to find out why their
                task changed, and they can't edit anything anyway. */}
            <div className="inline-flex rounded-md border p-0.5">
              <Button
                size="sm"
                variant={view === "build" ? "secondary" : "ghost"}
                onClick={() => setView("build")}
              >
                Build
              </Button>
              <Button
                size="sm"
                variant={view === "history" ? "secondary" : "ghost"}
                onClick={() => setView("history")}
                data-testid="rule-history-tab"
              >
                History
              </Button>
            </div>

            {view === "build" && problem && (
              <Alert>
                <AlertDescription>{problem}</AlertDescription>
              </Alert>
            )}

            {view === "history" ? (
              <AutomationRunLog automationId={selectedRule.id} />
            ) : (
              <>
            {/* Palette — built from the server catalog, never hardcoded.
                Grouped by category with a one-line explanation, so the three
                kinds of step read as a model (listen → gate → do) rather than
                as three undifferentiated rows of buttons. */}
            {canEdit && (
              <div className="hidden flex-col gap-2 lg:flex">
                {palette.map(([kind, steps]) => (
                  <div key={kind} className="flex flex-wrap items-center gap-1.5">
                    <span
                      className="w-16 shrink-0 text-xs font-medium"
                      title={KIND_HINT[kind]}
                    >
                      {KIND_PALETTE_LABEL[kind]}
                    </span>
                    <span className="mr-1 hidden text-xs text-muted-foreground 2xl:inline">
                      {KIND_HINT[kind]}
                    </span>
                    {steps.map((step) => (
                      <Button
                        key={step.type}
                        size="sm"
                        variant="outline"
                        className="h-7 text-xs"
                        title={step.description}
                        onClick={() => addStep(kind, step.type)}
                      >
                        <Plus className="h-3 w-3" /> {step.label}
                      </Button>
                    ))}
                  </div>
                ))}
              </div>
            )}

            <p className="text-xs text-muted-foreground lg:hidden">
              Rules are read-only on a small screen — connecting steps needs a
              pointer. Open this board on a larger display to edit.
            </p>

            <div className="grid gap-3 xl:grid-cols-[1fr_280px]">
              <AutomationCanvas
                graph={draft}
                catalog={catalog}
                readOnly={!canEdit}
                selectedId={selectedNodeId}
                onSelect={setSelectedNodeId}
                onChange={setDraft}
                onReject={setError}
              />

              {/* Scrolls inside itself rather than stretching the row, so a
                  step with many fields can't push the canvas off-screen. */}
              <Card className="max-h-[calc(100vh-20rem)] min-h-[520px] overflow-y-auto p-3">
                <p className="mb-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {selectedNode ? "Step settings" : "Nothing selected"}
                </p>
                {selectedNode ? (
                  <StepConfigPanel
                    node={selectedNode}
                    definition={definitionFor(catalog, selectedNode.kind, selectedNode.type)}
                    readOnly={!canEdit}
                    sections={sections}
                    members={members}
                    onChange={(config) =>
                      setDraft({
                        ...draft,
                        nodes: draft.nodes.map((n) =>
                          n.id === selectedNode.id ? { ...n, config } : n,
                        ),
                      })
                    }
                  />
                ) : (
                  <p className="text-sm text-muted-foreground">
                    Click a step on the canvas to configure it.
                  </p>
                )}
              </Card>
            </div>
              </>
            )}
          </div>
        )}
      </div>
    </div>
  );
};

export default AutomationsTab;
