import { describe, expect, it } from "vitest";
import {
  defaultConfigFor,
  definitionFor,
  describeProblem,
  nextNodeId,
  removeNode,
  startingGraph,
  wouldCycle,
  type AutomationGraph,
  type StepCatalog,
} from "./automationTypes";

const graph = (
  nodes: [string, "trigger" | "condition" | "action"][],
  edges: [string, string][],
): AutomationGraph => ({
  nodes: nodes.map(([id, kind]) => ({ id, kind, type: `x.${kind}`, config: {} })),
  edges: edges.map(([from, to]) => ({ from, to })),
});

describe("wouldCycle", () => {
  it("catches a self-connection", () => {
    expect(wouldCycle(graph([["a", "action"]], []), "a", "a")).toBe(true);
  });

  it("catches a direct back-edge", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["a", "action"],
      ],
      [["t", "a"]],
    );
    expect(wouldCycle(g, "a", "t")).toBe(true);
  });

  it("catches a longer ring", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["a", "action"],
        ["b", "action"],
      ],
      [
        ["t", "a"],
        ["a", "b"],
      ],
    );
    // b → t would close t → a → b → t.
    expect(wouldCycle(g, "b", "t")).toBe(true);
  });

  it("allows a diamond, which is not a cycle", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["l", "condition"],
        ["r", "condition"],
        ["e", "action"],
      ],
      [
        ["t", "l"],
        ["t", "r"],
        ["l", "e"],
      ],
    );
    expect(wouldCycle(g, "r", "e")).toBe(false);
  });
});

describe("describeProblem", () => {
  it("wants a trigger", () => {
    expect(describeProblem(graph([["a", "action"]], []))).toContain("needs a trigger");
  });

  it("rejects two triggers", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["t2", "trigger"],
      ],
      [],
    );
    expect(describeProblem(g)).toContain("only have one trigger");
  });

  it("wants more than a lone trigger", () => {
    expect(describeProblem(startingGraph("task.created"))).toContain("at least one action");
  });

  it("catches a floating branch", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["a", "action"],
        ["lost", "action"],
      ],
      [["t", "a"]],
    );
    expect(describeProblem(g)).toContain("floating loose");
  });

  it("is happy with a connected rule", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["a", "action"],
      ],
      [["t", "a"]],
    );
    expect(describeProblem(g)).toBeNull();
  });
});

describe("nextNodeId", () => {
  it("skips ids already in use", () => {
    const g = graph(
      [
        ["action-1", "action"],
        ["action-2", "action"],
      ],
      [],
    );
    expect(nextNodeId(g, "action")).toBe("action-3");
  });

  it("starts at one on an empty graph", () => {
    expect(nextNodeId(graph([], []), "condition")).toBe("condition-1");
  });
});

describe("removeNode", () => {
  it("takes its edges with it", () => {
    const g = graph(
      [
        ["t", "trigger"],
        ["c", "condition"],
        ["a", "action"],
      ],
      [
        ["t", "c"],
        ["c", "a"],
      ],
    );

    const next = removeNode(g, "c");
    expect(next.nodes.map((n) => n.id)).toEqual(["t", "a"]);
    // Both edges touched `c`, so both go — leaving a dangling edge would make
    // the graph unparseable server-side.
    expect(next.edges).toEqual([]);
  });
});

describe("definitionFor", () => {
  const catalog: StepCatalog = {
    triggers: [{ type: "task.created", label: "Created", description: "", configSchema: [] }],
    conditions: [],
    actions: [{ type: "task.assign", label: "Assign", description: "", configSchema: [] }],
  };

  it("looks in the right pool for the kind", () => {
    expect(definitionFor(catalog, "trigger", "task.created")?.label).toBe("Created");
    expect(definitionFor(catalog, "action", "task.assign")?.label).toBe("Assign");
  });

  it("returns null for a type this client doesn't have", () => {
    expect(definitionFor(catalog, "action", "task.unknown")).toBeNull();
    expect(definitionFor(null, "action", "task.assign")).toBeNull();
  });
});

describe("defaultConfigFor", () => {
  it("seeds selects and numbers but leaves text alone", () => {
    expect(
      defaultConfigFor([
        { key: "mode", type: "select", label: "", options: [{ value: "a", label: "A" }] },
        { key: "days", type: "number", label: "", min: 3 },
        { key: "note", type: "text", label: "" },
      ]),
    ).toEqual({ mode: "a", days: 3 });
  });
});
