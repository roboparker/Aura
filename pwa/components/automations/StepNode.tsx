import { Handle, Position, type NodeProps } from "@xyflow/react";
import { Diamond, Play, Trash2, Zap } from "lucide-react";
import { KIND_LABEL, type NodeKind } from "@/lib/automationTypes";

export interface StepNodeData extends Record<string, unknown> {
  kind: NodeKind;
  label: string;
  summary: string;
  /** True when this client has no definition for the step's type. */
  unknown: boolean;
  readOnly: boolean;
  onRemove: (id: string) => void;
}

const ICON: Record<NodeKind, typeof Zap> = {
  trigger: Play,
  condition: Diamond,
  action: Zap,
};

/**
 * Tone by kind, so a rule's shape reads at a glance before any text does —
 * where it starts, where it forks, what it does.
 */
const TONE: Record<NodeKind, string> = {
  trigger: "border-sky-500/60 bg-sky-500/5",
  condition: "border-amber-500/60 bg-amber-500/5",
  action: "border-emerald-500/60 bg-emerald-500/5",
};

/**
 * One step on the canvas (#764).
 *
 * A trigger has no input handle — nothing can lead into it, which is also
 * enforced server-side. Making that visible stops people trying.
 */
const StepNode = ({ id, data, selected }: NodeProps) => {
  const step = data as StepNodeData;
  const Icon = ICON[step.kind];

  return (
    <div
      className={`w-56 rounded-lg border-2 p-2.5 shadow-sm transition-colors ${
        step.unknown ? "border-dashed border-muted-foreground/50 bg-muted/30" : TONE[step.kind]
      } ${selected ? "ring-2 ring-ring" : ""}`}
      data-testid={`step-node-${id}`}
    >
      {step.kind !== "trigger" && (
        <Handle type="target" position={Position.Left} className="!h-2.5 !w-2.5" />
      )}

      <div className="flex items-start gap-2">
        <Icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
            {KIND_LABEL[step.kind]}
          </p>
          <p className="truncate text-sm font-medium">{step.label}</p>
          {step.summary && (
            <p className="truncate text-xs text-muted-foreground">{step.summary}</p>
          )}
        </div>

        {/* The trigger is the rule's root — removing it would leave something
            that can never fire, so it isn't removable. */}
        {!step.readOnly && step.kind !== "trigger" && (
          <button
            type="button"
            aria-label={`Remove ${step.label}`}
            className="shrink-0 text-muted-foreground hover:text-destructive"
            onClick={(e) => {
              e.stopPropagation();
              step.onRemove(id);
            }}
          >
            <Trash2 className="h-3.5 w-3.5" />
          </button>
        )}
      </div>

      <Handle type="source" position={Position.Right} className="!h-2.5 !w-2.5" />
    </div>
  );
};

export default StepNode;
