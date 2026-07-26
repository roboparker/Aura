import { describe, expect, it } from "vitest";
import {
  defaultConfigFor,
  groupCatalog,
  type WidgetCatalogEntry,
  type WidgetConfigField,
} from "./dashboardTypes";

const entry = (type: string, group: string): WidgetCatalogEntry => ({
  type,
  name: type,
  description: "",
  group,
  defaultSize: { w: 2, h: 2 },
  configSchema: [],
});

describe("defaultConfigFor", () => {
  it("seeds a select from its first option so the widget renders immediately", () => {
    const schema: WidgetConfigField[] = [
      {
        key: "metric",
        type: "select",
        label: "Metric",
        options: [
          { value: "invoiced", label: "Invoiced" },
          { value: "collected", label: "Collected" },
        ],
      },
    ];
    expect(defaultConfigFor(schema)).toEqual({ metric: "invoiced" });
  });

  it("seeds a number from its minimum", () => {
    const schema: WidgetConfigField[] = [
      { key: "limit", type: "number", label: "Rows", min: 3, max: 20 },
    ];
    expect(defaultConfigFor(schema)).toEqual({ limit: 3 });
  });

  it("leaves text and markdown unset — an empty note is a valid start", () => {
    const schema: WidgetConfigField[] = [
      { key: "title", type: "text", label: "Heading" },
      { key: "body", type: "markdown", label: "Text" },
    ];
    expect(defaultConfigFor(schema)).toEqual({});
  });

  it("defaults a boolean to off rather than leaving it undefined", () => {
    const schema: WidgetConfigField[] = [
      { key: "dueOnly", type: "boolean", label: "Only due" },
    ];
    expect(defaultConfigFor(schema)).toEqual({ dueOnly: false });
  });

  it("handles a select with no options without inventing a value", () => {
    const schema: WidgetConfigField[] = [
      { key: "metric", type: "select", label: "Metric", options: [] },
    ];
    expect(defaultConfigFor(schema)).toEqual({});
  });
});

describe("groupCatalog", () => {
  it("groups by section, keeping first-seen order", () => {
    const grouped = groupCatalog([
      entry("analytics.metric", "Analytics"),
      entry("boards.list", "Work"),
      entry("analytics.stat", "Analytics"),
    ]);

    expect(grouped.map((g) => g.group)).toEqual(["Analytics", "Work"]);
    expect(grouped[0].entries.map((e) => e.type)).toEqual([
      "analytics.metric",
      "analytics.stat",
    ]);
  });

  it("is empty for an empty catalog", () => {
    expect(groupCatalog([])).toEqual([]);
  });
});
