/**
 * Read-only schedule analysis for the board Timeline (#timeline).
 *
 * Deliberately analysis-only: nothing here edits a task. A Gantt that silently
 * rewrites dates when you drag one bar can rewrite many people's deadlines from
 * a single gesture, so this surfaces problems and leaves the fix to a human.
 *
 * Pure functions over plain data so they're unit-testable without a DOM.
 */

export interface AnalysisEdge {
  source: string;
  target: string;
  type: string;
}

export interface AnalysisBar {
  id: string;
  /** Inclusive start day (integer day-number). */
  startDay: number;
  /** Inclusive end day. */
  endDay: number;
}

export interface ScheduleAnalysis {
  /** Task ids on the longest dependency chain by calendar span. */
  criticalTaskIds: Set<string>;
  /** `required` edges whose dependent starts before its prerequisite ends. */
  violatedEdgeKeys: Set<string>;
}

/** Stable key for an edge, so the renderer can look up a verdict. */
export const edgeKey = (edge: AnalysisEdge): string =>
  `${edge.source}->${edge.target}:${edge.type}`;

/**
 * The critical path: the chain of `required` dependencies whose total calendar
 * span is longest, i.e. the sequence that actually determines when the work can
 * finish. Slipping anything on it slips the end date; slipping anything off it
 * has slack.
 *
 * Longest-path over a DAG, computed by memoised DFS. Task relationships already
 * forbid cycles for `parent`, but `required` has no such guard, so a `visiting`
 * set makes a cyclic chain terminate (and simply not be treated as critical)
 * rather than hang the browser.
 */
export const analyseSchedule = (
  bars: AnalysisBar[],
  edges: AnalysisEdge[],
): ScheduleAnalysis => {
  const barById = new Map(bars.map((b) => [b.id, b]));
  const required = edges.filter(
    (e) => e.type === "required" && barById.has(e.source) && barById.has(e.target),
  );

  // Adjacency: predecessor -> dependents.
  const next = new Map<string, string[]>();
  for (const edge of required) {
    const list = next.get(edge.source);
    if (list) list.push(edge.target);
    else next.set(edge.source, [edge.target]);
  }

  const span = (id: string): number => {
    const bar = barById.get(id);
    return bar ? bar.endDay - bar.startDay + 1 : 0;
  };

  // best[id] = { length, path } for the longest chain starting at id.
  const best = new Map<string, { length: number; path: string[] }>();
  const visiting = new Set<string>();

  const walk = (id: string): { length: number; path: string[] } => {
    const cached = best.get(id);
    if (cached) return cached;
    if (visiting.has(id)) {
      // Cyclic `required` chain — stop rather than recurse forever.
      return { length: 0, path: [] };
    }

    visiting.add(id);
    let bestChild: { length: number; path: string[] } = { length: 0, path: [] };
    for (const child of next.get(id) ?? []) {
      const candidate = walk(child);
      if (candidate.length > bestChild.length) bestChild = candidate;
    }
    visiting.delete(id);

    const result = {
      length: span(id) + bestChild.length,
      path: [id, ...bestChild.path],
    };
    best.set(id, result);
    return result;
  };

  let longest: { length: number; path: string[] } = { length: 0, path: [] };
  for (const bar of bars) {
    const candidate = walk(bar.id);
    if (candidate.length > longest.length) longest = candidate;
  }

  // A single task with no dependencies isn't a "path" worth highlighting —
  // the critical path is only meaningful once something depends on something.
  const criticalTaskIds = new Set(longest.path.length > 1 ? longest.path : []);

  // A dependency is violated when the dependent starts before its prerequisite
  // finishes: the work is scheduled to begin before it possibly could.
  const violatedEdgeKeys = new Set<string>();
  for (const edge of required) {
    const from = barById.get(edge.source);
    const to = barById.get(edge.target);
    if (from && to && to.startDay <= from.endDay) {
      violatedEdgeKeys.add(edgeKey(edge));
    }
  }

  return { criticalTaskIds, violatedEdgeKeys };
};

/**
 * Order rows so children follow their parent, with a depth for indentation.
 *
 * Roots keep the incoming order (the caller's section ordering); a task whose
 * parent isn't in the same list is treated as a root, which is what makes this
 * safe on a board-scoped chart where a parent may live elsewhere. Depth is
 * capped defensively so a malformed chain can't produce runaway indentation.
 */
export const nestByParent = <T extends { id: string }>(
  items: T[],
  edges: AnalysisEdge[],
  maxDepth = 5,
): { item: T; depth: number }[] => {
  const present = new Set(items.map((i) => i.id));
  const parentOf = new Map<string, string>();
  const childrenOf = new Map<string, string[]>();

  for (const edge of edges) {
    if (edge.type !== "parent") continue;
    if (!present.has(edge.source) || !present.has(edge.target)) continue;
    // Single-parent is enforced server-side; last one wins if that ever slips.
    parentOf.set(edge.target, edge.source);
    const list = childrenOf.get(edge.source);
    if (list) list.push(edge.target);
    else childrenOf.set(edge.source, [edge.target]);
  }

  const byId = new Map(items.map((i) => [i.id, i]));
  const out: { item: T; depth: number }[] = [];
  const emitted = new Set<string>();

  const emit = (id: string, depth: number): void => {
    if (emitted.has(id) || depth > maxDepth) return;
    const item = byId.get(id);
    if (!item) return;
    emitted.add(id);
    out.push({ item, depth });
    for (const child of childrenOf.get(id) ?? []) emit(child, depth + 1);
  };

  for (const item of items) {
    if (!parentOf.has(item.id)) emit(item.id, 0);
  }
  // Anything still unemitted belongs to a cycle or an orphaned chain — show it
  // flat rather than dropping it silently.
  for (const item of items) emit(item.id, 0);

  return out;
};
