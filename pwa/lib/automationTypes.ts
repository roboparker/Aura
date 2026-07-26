/**
 * Wire types and graph helpers for board automations (#764).
 *
 * Mirrors `App\Automation\AutomationGraph` — keep the two in step. The client
 * validates the same rules the server does, not to be trusted (the server
 * re-checks everything) but so the canvas can refuse a bad connection at the
 * moment it's drawn instead of on save.
 */

export type NodeKind = "trigger" | "condition" | "action";

/** One field in a step's config editor, as declared by the server. */
export interface StepConfigField {
  key: string;
  type: "text" | "textarea" | "number" | "select" | "boolean" | "section" | "space_member";
  label: string;
  placeholder?: string;
  min?: number;
  max?: number;
  options?: { value: string | number; label: string }[];
}

/** A palette entry. */
export interface StepDefinition {
  type: string;
  label: string;
  description: string;
  /** Triggers only. */
  event?: string;
  configSchema: StepConfigField[];
}

export interface StepCatalog {
  triggers: StepDefinition[];
  conditions: StepDefinition[];
  actions: StepDefinition[];
}

export interface GraphNode {
  id: string;
  kind: NodeKind;
  type: string;
  config: Record<string, unknown>;
  /**
   * Canvas coordinates. The server ignores this key entirely — it round-trips
   * through the stored JSON untouched, so layout lives with the rule without
   * the backend needing to know what a canvas is.
   */
  position?: { x: number; y: number };
}

export interface GraphEdge {
  from: string;
  to: string;
}

export interface AutomationGraph {
  nodes: GraphNode[];
  edges: GraphEdge[];
}

export interface Automation {
  id: string;
  name: string;
  enabled: boolean;
  triggerEvent: string;
  graph: AutomationGraph;
  /** Set when the server auto-disabled the rule; shown so it isn't a mystery. */
  lastError: string | null;
  lastRunAt: string | null;
}

export interface AutomationRunStep {
  node: string;
  kind: NodeKind;
  type: string;
  outcome: "passed" | "skipped" | "done" | "error";
  detail: string;
}

export interface AutomationRun {
  id: string;
  status: "applied" | "no_match" | "failed";
  taskTitle: string;
  taskId: string | null;
  depth: number;
  steps: AutomationRunStep[];
  ranAt: string;
}

export const KIND_LABEL: Record<NodeKind, string> = {
  trigger: "When",
  condition: "Only if",
  action: "Then",
};

/** An empty rule still needs its trigger — a graph without one can never run. */
export const startingGraph = (triggerType: string): AutomationGraph => ({
  nodes: [
    {
      id: "trigger",
      kind: "trigger",
      type: triggerType,
      config: {},
      position: { x: 60, y: 140 },
    },
  ],
  edges: [],
});

/** Ids only need to be unique within one rule, so a counter beats a uuid. */
export const nextNodeId = (graph: AutomationGraph, kind: NodeKind): string => {
  const taken = new Set(graph.nodes.map((node) => node.id));
  // Bounded by the node count + 1: with N nodes, one of the first N+1
  // candidates is always free.
  for (let n = 1; n <= taken.size + 1; n += 1) {
    const candidate = `${kind}-${n}`;
    if (!taken.has(candidate)) return candidate;
  }
  return `${kind}-${taken.size + 1}`;
};

/**
 * Whether adding `from → to` would create a cycle.
 *
 * Checked as the connection is drawn. The server rejects cycles too, but
 * discovering it on save — after arranging a whole rule — is a miserable way to
 * find out.
 */
export const wouldCycle = (
  graph: AutomationGraph,
  from: string,
  to: string,
): boolean => {
  if (from === to) return true;

  // Can we already get from `to` back to `from`? If so, the new edge closes a loop.
  const seen = new Set<string>();
  const stack = [to];
  while (stack.length > 0) {
    const current = stack.pop() as string;
    if (current === from) return true;
    if (seen.has(current)) continue;
    seen.add(current);
    for (const edge of graph.edges) {
      if (edge.from === current) stack.push(edge.to);
    }
  }
  return false;
};

/**
 * Why this graph can't be saved yet, or null when it's fine.
 *
 * Mirrors the server's checks so the canvas can explain the problem in place,
 * rather than surfacing a 422 after the fact.
 */
export const describeProblem = (graph: AutomationGraph): string | null => {
  const triggers = graph.nodes.filter((n) => n.kind === "trigger");
  if (triggers.length === 0) return "This rule needs a trigger to start it.";
  if (triggers.length > 1) return "A rule can only have one trigger.";

  if (graph.nodes.length === 1) {
    return "Add at least one action — a trigger on its own does nothing.";
  }

  // Everything must hang off the trigger; a floating branch never runs.
  const reachable = new Set<string>();
  const stack = [triggers[0].id];
  while (stack.length > 0) {
    const current = stack.pop() as string;
    if (reachable.has(current)) continue;
    reachable.add(current);
    for (const edge of graph.edges) {
      if (edge.from === current) stack.push(edge.to);
    }
  }

  const orphan = graph.nodes.find((n) => !reachable.has(n.id));
  if (orphan) {
    return "Every step must connect back to the trigger — some are floating loose.";
  }

  return null;
};

/** Removes a node and any edges touching it. */
export const removeNode = (
  graph: AutomationGraph,
  nodeId: string,
): AutomationGraph => ({
  nodes: graph.nodes.filter((n) => n.id !== nodeId),
  edges: graph.edges.filter((e) => e.from !== nodeId && e.to !== nodeId),
});

/** Finds a step's definition in the catalog, whatever kind it is. */
export const definitionFor = (
  catalog: StepCatalog | null,
  kind: NodeKind,
  type: string,
): StepDefinition | null => {
  if (!catalog) return null;
  const pool =
    kind === "trigger"
      ? catalog.triggers
      : kind === "condition"
        ? catalog.conditions
        : catalog.actions;
  return pool.find((d) => d.type === type) ?? null;
};

/** Seeds a config so a freshly dropped step is valid where it can be. */
export const defaultConfigFor = (
  schema: StepConfigField[],
): Record<string, unknown> => {
  const config: Record<string, unknown> = {};
  for (const field of schema) {
    if (field.type === "select" && field.options?.length) {
      config[field.key] = field.options[0].value;
    } else if (field.type === "number") {
      config[field.key] = field.min ?? 1;
    } else if (field.type === "boolean") {
      config[field.key] = false;
    }
  }
  return config;
};
