import { useCallback, useMemo } from "react";
import {
  Background,
  Controls,
  ReactFlow,
  addEdge,
  type Connection,
  type Edge,
  type Node,
  type NodeChange,
  type ReactFlowInstance,
} from "@xyflow/react";
import "@xyflow/react/dist/style.css";
import {
  definitionFor,
  wouldCycle,
  type AutomationGraph,
  type GraphNode,
  type StepCatalog,
} from "@/lib/automationTypes";
import StepNode, { type StepNodeData } from "./StepNode";

interface Props {
  graph: AutomationGraph;
  catalog: StepCatalog | null;
  readOnly: boolean;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  onChange: (graph: AutomationGraph) => void;
  /** Rejected connections surface here rather than failing silently. */
  onReject: (reason: string) => void;
}

/** Fallback positions for a rule saved before layout existed, or hand-authored. */
const fallbackPosition = (index: number) => ({
  x: 60 + (index % 3) * 260,
  y: 60 + Math.floor(index / 3) * 140,
});

/**
 * The rule graph as a canvas (#764).
 *
 * Node positions live in the stored graph JSON, which the server parses but
 * ignores — layout travels with the rule without the backend needing to know
 * what a canvas is.
 *
 * **Connections that would create a cycle are refused as they're drawn.** The
 * server rejects them too, but finding out on save, after arranging a whole
 * rule, is a miserable way to learn.
 */
const AutomationCanvas = ({
  graph,
  catalog,
  readOnly,
  selectedId,
  onSelect,
  onChange,
  onReject,
}: Props) => {
  const nodes: Node[] = useMemo(
    () =>
      graph.nodes.map((node, i) => {
        const definition = definitionFor(catalog, node.kind, node.type);
        return {
          id: node.id,
          type: "step",
          position: node.position ?? fallbackPosition(i),
          selected: node.id === selectedId,
          data: {
            kind: node.kind,
            label: definition?.label ?? node.type,
            summary: summarise(node),
            unknown: catalog !== null && definition === null,
            readOnly,
            onRemove: (id: string) =>
              onChange({
                nodes: graph.nodes.filter((n) => n.id !== id),
                edges: graph.edges.filter((e) => e.from !== id && e.to !== id),
              }),
          } satisfies StepNodeData,
        };
      }),
    [graph, catalog, readOnly, selectedId, onChange],
  );

  const edges: Edge[] = useMemo(
    () =>
      graph.edges.map((edge) => ({
        id: `${edge.from}->${edge.to}`,
        source: edge.from,
        target: edge.to,
        animated: true,
      })),
    [graph.edges],
  );

  const nodeTypes = useMemo(() => ({ step: StepNode }), []);

  const handleConnect = useCallback(
    (connection: Connection) => {
      const { source, target } = connection;
      if (!source || !target) return;

      if (wouldCycle(graph, source, target)) {
        onReject("That connection would make the rule loop back on itself.");
        return;
      }
      if (graph.edges.some((e) => e.from === source && e.to === target)) {
        return;
      }
      const targetNode = graph.nodes.find((n) => n.id === target);
      if (targetNode?.kind === "trigger") {
        onReject("Nothing can lead into the trigger — it starts the rule.");
        return;
      }

      // addEdge is only used for its dedupe/shape handling; the graph itself
      // stays the source of truth.
      addEdge(connection, edges);
      onChange({ ...graph, edges: [...graph.edges, { from: source, to: target }] });
    },
    [graph, edges, onChange, onReject],
  );

  /** Persist a drag so layout survives a reload. */
  const handleNodesChange = useCallback(
    (changes: NodeChange[]) => {
      if (readOnly) return;

      const moves = changes.filter(
        (c): c is NodeChange & { id: string; position: { x: number; y: number } } =>
          c.type === "position" && "position" in c && c.position !== undefined,
      );
      if (moves.length === 0) return;

      onChange({
        ...graph,
        nodes: graph.nodes.map((node) => {
          const move = moves.find((m) => m.id === node.id);
          return move ? { ...node, position: move.position } : node;
        }),
      });
    },
    [graph, readOnly, onChange],
  );

  const handleEdgesDelete = useCallback(
    (deleted: Edge[]) => {
      if (readOnly) return;
      const gone = new Set(deleted.map((e) => `${e.source}->${e.target}`));
      onChange({
        ...graph,
        edges: graph.edges.filter((e) => !gone.has(`${e.from}->${e.to}`)),
      });
    },
    [graph, readOnly, onChange],
  );

  return (
    <div className="h-[520px] w-full rounded-md border">
      <ReactFlow
        nodes={nodes}
        edges={edges}
        nodeTypes={nodeTypes}
        onConnect={readOnly ? undefined : handleConnect}
        onNodesChange={handleNodesChange}
        onEdgesDelete={handleEdgesDelete}
        onNodeClick={(_, node) => onSelect(node.id)}
        onPaneClick={() => onSelect(null)}
        nodesDraggable={!readOnly}
        nodesConnectable={!readOnly}
        edgesReconnectable={false}
        fitView
        proOptions={{ hideAttribution: false }}
        onInit={(instance: ReactFlowInstance) => instance.fitView({ padding: 0.2 })}
      >
        <Background />
        <Controls showInteractive={false} />
      </ReactFlow>
    </div>
  );
};

/** A one-line gloss of a step's config, so the node says what it actually does. */
const summarise = (node: GraphNode): string => {
  const entries = Object.entries(node.config).filter(
    ([, value]) => value !== undefined && value !== "" && value !== null,
  );
  if (entries.length === 0) return "";

  return entries
    .slice(0, 2)
    .map(([key, value]) => `${key}: ${String(value)}`)
    .join(" · ");
};

export default AutomationCanvas;
