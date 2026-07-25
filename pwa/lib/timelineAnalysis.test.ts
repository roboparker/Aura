import { describe, expect, it } from "vitest";
import {
  analyseSchedule,
  edgeKey,
  nestByParent,
  type AnalysisBar,
  type AnalysisEdge,
} from "./timelineAnalysis";

const bar = (id: string, startDay: number, endDay: number): AnalysisBar => ({
  id,
  startDay,
  endDay,
});
const req = (source: string, target: string): AnalysisEdge => ({
  source,
  target,
  type: "required",
});
const parent = (source: string, target: string): AnalysisEdge => ({
  source,
  target,
  type: "parent",
});

describe("analyseSchedule — critical path", () => {
  it("picks the longest chain by span, not the one with most hops", () => {
    // a → b → c spans 3 days total; a → d spans 10. The long single hop wins.
    const bars = [bar("a", 0, 0), bar("b", 1, 1), bar("c", 2, 2), bar("d", 1, 9)];
    const { criticalTaskIds } = analyseSchedule(bars, [
      req("a", "b"),
      req("b", "c"),
      req("a", "d"),
    ]);

    expect([...criticalTaskIds].sort()).toEqual(["a", "d"]);
  });

  it("highlights nothing when no task depends on another", () => {
    // A lone bar is not a "path" — highlighting it would be noise.
    const { criticalTaskIds } = analyseSchedule([bar("a", 0, 5)], []);
    expect(criticalTaskIds.size).toBe(0);
  });

  it("terminates on a cyclic required chain instead of hanging", () => {
    const bars = [bar("a", 0, 1), bar("b", 2, 3)];
    const { criticalTaskIds } = analyseSchedule(bars, [req("a", "b"), req("b", "a")]);
    // The guarantee is that it returns at all; a cycle has no meaningful path.
    expect(criticalTaskIds).toBeInstanceOf(Set);
  });

  it("ignores edges pointing at tasks that aren't charted", () => {
    const { criticalTaskIds } = analyseSchedule(
      [bar("a", 0, 1)],
      [req("a", "offboard")],
    );
    expect(criticalTaskIds.size).toBe(0);
  });
});

describe("analyseSchedule — violated dependencies", () => {
  it("flags a dependent that starts before its prerequisite ends", () => {
    const bars = [bar("a", 0, 5), bar("b", 3, 8)];
    const edge = req("a", "b");
    const { violatedEdgeKeys } = analyseSchedule(bars, [edge]);

    expect(violatedEdgeKeys.has(edgeKey(edge))).toBe(true);
  });

  it("accepts a dependent starting the day after its prerequisite ends", () => {
    const bars = [bar("a", 0, 5), bar("b", 6, 8)];
    const edge = req("a", "b");
    const { violatedEdgeKeys } = analyseSchedule(bars, [edge]);

    expect(violatedEdgeKeys.size).toBe(0);
  });

  it("does not flag parent links — only real dependencies", () => {
    const bars = [bar("a", 0, 5), bar("b", 1, 2)];
    const { violatedEdgeKeys } = analyseSchedule(bars, [parent("a", "b")]);
    // A subtask living inside its parent's window is normal, not a violation.
    expect(violatedEdgeKeys.size).toBe(0);
  });
});

describe("nestByParent", () => {
  const item = (id: string) => ({ id });

  it("places children directly after their parent, indented", () => {
    const rows = nestByParent(
      [item("p"), item("other"), item("c1"), item("c2")],
      [parent("p", "c1"), parent("p", "c2")],
    );

    expect(rows.map((r) => [r.item.id, r.depth])).toEqual([
      ["p", 0],
      ["c1", 1],
      ["c2", 1],
      ["other", 0],
    ]);
  });

  it("treats a task whose parent is off-chart as a root", () => {
    // Board-scoped charts legitimately omit a parent that lives elsewhere.
    const rows = nestByParent([item("child")], [parent("elsewhere", "child")]);
    expect(rows).toEqual([{ item: { id: "child" }, depth: 0 }]);
  });

  it("emits every row exactly once even in a malformed cycle", () => {
    const rows = nestByParent(
      [item("a"), item("b")],
      [parent("a", "b"), parent("b", "a")],
    );
    expect(rows.map((r) => r.item.id).sort()).toEqual(["a", "b"]);
  });

  it("nests grandchildren deeper", () => {
    const rows = nestByParent(
      [item("p"), item("c"), item("g")],
      [parent("p", "c"), parent("c", "g")],
    );
    expect(rows.map((r) => r.depth)).toEqual([0, 1, 2]);
  });
});
