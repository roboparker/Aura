"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";

/**
 * Reusable nested permission editor.
 *
 * The data model is flat and granular: a map of leaf key → level value
 * (e.g. `{ tasks: "view", files: "none" }`). The UX is nested three deep:
 *
 *   - a top "master" row (None / Custom / All) that bulk-sets every leaf,
 *   - one row per group (None / Custom / All) that bulk-sets its leaves and
 *     expands to reveal them,
 *   - one row per leaf with the fine-grained level control.
 *
 * "None" sets the subtree to the first (least-privileged) level, "All" to
 * the last (most-privileged) level, and "Custom" expands the subtree so the
 * leaves can be set individually. The derived parent state is None/All when
 * every descendant agrees on the extreme, otherwise Custom.
 *
 * It's deliberately feature-agnostic — impersonation consent is the first
 * caller, but the same control backs API-key scopes and future per-feature
 * permission grants. Pass your own `levels` (ordered least → most
 * privileged) and `nodes`.
 */
export interface PermissionLevel {
  value: string;
  label: string;
}

export interface PermissionLeafNode {
  key: string;
  label: string;
  description?: string;
}

export interface PermissionGroupNode {
  key: string;
  label: string;
  description?: string;
  children: PermissionLeafNode[];
}

export type PermissionNode = PermissionLeafNode | PermissionGroupNode;

const isGroup = (node: PermissionNode): node is PermissionGroupNode =>
  "children" in node;

type ParentState = "none" | "custom" | "all";

const PARENT_OPTIONS: { value: ParentState; label: string }[] = [
  { value: "none", label: "None" },
  { value: "custom", label: "Custom" },
  { value: "all", label: "All" },
];

/** Generic segmented selector. `value` may be "" so nothing is highlighted. */
const Segmented = ({
  options,
  value,
  onSelect,
  ariaLabel,
}: {
  options: { value: string; label: string }[];
  value: string;
  onSelect: (value: string) => void;
  ariaLabel: string;
}) => (
  <div
    role="group"
    aria-label={ariaLabel}
    className="inline-flex shrink-0 gap-0.5 rounded-md border p-0.5"
  >
    {options.map((opt) => {
      const active = value === opt.value;
      return (
        <button
          key={opt.value}
          type="button"
          aria-pressed={active}
          onClick={() => onSelect(opt.value)}
          className={cn(
            "rounded px-2.5 py-1 text-xs font-medium transition-colors",
            active
              ? "bg-primary text-primary-foreground"
              : "text-muted-foreground hover:bg-accent",
          )}
        >
          {opt.label}
        </button>
      );
    })}
  </div>
);

export interface PermissionTreeProps {
  /** Levels ordered least → most privileged. First = "None", last = "All". */
  levels: PermissionLevel[];
  nodes: PermissionNode[];
  /** leaf key → level value. Missing keys fall back to the first level. */
  values: Record<string, string>;
  onChange: (next: Record<string, string>) => void;
  /** Label for the top bulk row. */
  masterLabel?: string;
}

const PermissionTree = ({
  levels,
  nodes,
  values,
  onChange,
  masterLabel = "All categories",
}: PermissionTreeProps) => {
  const none = levels[0].value;
  const all = levels[levels.length - 1].value;

  const allLeafKeys = nodes.flatMap((n) =>
    isGroup(n) ? n.children.map((c) => c.key) : [n.key],
  );

  const levelOf = (key: string) => values[key] ?? none;

  const setLeaves = (keys: string[], level: string) => {
    const next = { ...values };
    for (const k of keys) next[k] = level;
    onChange(next);
  };

  const parentStateOf = (keys: string[]): ParentState => {
    if (keys.every((k) => levelOf(k) === none)) return "none";
    if (keys.every((k) => levelOf(k) === all)) return "all";
    return "custom";
  };

  return (
    <div className="space-y-1">
      <div className="mb-1 flex items-center justify-between gap-4 pb-1">
        <p className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
          {masterLabel}
        </p>
        <Segmented
          options={PARENT_OPTIONS}
          value={parentStateOf(allLeafKeys)}
          ariaLabel={`Set ${masterLabel}`}
          onSelect={(state) => {
            if (state === "none") setLeaves(allLeafKeys, none);
            else if (state === "all") setLeaves(allLeafKeys, all);
            // "custom" is informational at the master level — drill into a
            // group to make a mixed selection.
          }}
        />
      </div>

      {nodes.map((node) =>
        isGroup(node) ? (
          <GroupRow
            key={node.key}
            node={node}
            levels={levels}
            none={none}
            all={all}
            stateOf={parentStateOf}
            levelOf={levelOf}
            onSet={setLeaves}
          />
        ) : (
          <div
            key={node.key}
            className="flex items-center justify-between gap-4 py-1.5"
          >
            <div>
              <span className="text-sm font-medium">{node.label}</span>
              {node.description ? (
                <p className="text-xs text-muted-foreground">{node.description}</p>
              ) : null}
            </div>
            <Segmented
              options={levels}
              value={levelOf(node.key)}
              ariaLabel={`Set ${node.label}`}
              onSelect={(level) => setLeaves([node.key], level)}
            />
          </div>
        ),
      )}
    </div>
  );
};

const GroupRow = ({
  node,
  levels,
  none,
  all,
  stateOf,
  levelOf,
  onSet,
}: {
  node: PermissionGroupNode;
  levels: PermissionLevel[];
  none: string;
  all: string;
  stateOf: (keys: string[]) => ParentState;
  levelOf: (key: string) => string;
  onSet: (keys: string[], level: string) => void;
}) => {
  const childKeys = node.children.map((c) => c.key);
  const state = stateOf(childKeys);
  // Open by default when the group is already a mixed/custom selection.
  const [open, setOpen] = useState(state === "custom");

  return (
    <div className="rounded-md">
      <div className="flex items-center justify-between gap-4 py-1.5">
        <button
          type="button"
          onClick={() => setOpen((o) => !o)}
          className="flex items-center gap-1.5 text-left text-sm font-medium"
          aria-expanded={open}
        >
          <ChevronDown
            className={cn(
              "h-4 w-4 text-muted-foreground transition-transform",
              open ? "" : "-rotate-90",
            )}
            aria-hidden
          />
          {node.label}
          {node.description ? (
            <span className="text-xs font-normal text-muted-foreground">
              {node.description}
            </span>
          ) : null}
        </button>
        <Segmented
          options={PARENT_OPTIONS}
          value={state}
          ariaLabel={`Set ${node.label}`}
          onSelect={(next) => {
            if (next === "none") {
              onSet(childKeys, none);
              setOpen(false);
            } else if (next === "all") {
              onSet(childKeys, all);
              setOpen(false);
            } else {
              setOpen(true); // custom → reveal the leaves
            }
          }}
        />
      </div>
      {open ? (
        <div className="ml-6 space-y-1 border-l pl-3">
          {node.children.map((child) => (
            <div
              key={child.key}
              className="flex items-center justify-between gap-4 py-1"
            >
              <div>
                <span className="text-sm">{child.label}</span>
                {child.description ? (
                  <p className="text-xs text-muted-foreground">
                    {child.description}
                  </p>
                ) : null}
              </div>
              <Segmented
                options={levels}
                value={levelOf(child.key)}
                ariaLabel={`Set ${child.label}`}
                onSelect={(level) => onSet([child.key], level)}
              />
            </div>
          ))}
        </div>
      ) : null}
    </div>
  );
};

export default PermissionTree;
