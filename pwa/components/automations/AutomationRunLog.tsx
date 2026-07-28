import { useQuery } from "@tanstack/react-query";
import { AlertCircle, Check, CircleSlash, MinusCircle } from "lucide-react";
import { apiGet } from "@/lib/apiClient";
import { KIND_LABEL, type AutomationRun, type AutomationRunStep } from "@/lib/automationTypes";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";

interface Props {
  automationId: string;
}

/** Per-outcome affordances. `skipped` is information, not failure. */
const STEP_META: Record<
  AutomationRunStep["outcome"],
  { icon: typeof Check; tone: string; label: string }
> = {
  done: { icon: Check, tone: "text-emerald-600 dark:text-emerald-400", label: "did" },
  passed: { icon: Check, tone: "text-sky-600 dark:text-sky-400", label: "matched" },
  skipped: { icon: MinusCircle, tone: "text-muted-foreground", label: "skipped" },
  error: { icon: AlertCircle, tone: "text-destructive", label: "failed" },
};

const STATUS_META: Record<AutomationRun["status"], { label: string; variant: "default" | "secondary" | "destructive" }> = {
  applied: { label: "Applied", variant: "default" },
  no_match: { label: "No match", variant: "secondary" },
  failed: { label: "Failed", variant: "destructive" },
};

const when = (iso: string): string => {
  const date = new Date(iso);
  return date.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
};

/**
 * Recent executions of one rule (#764).
 *
 * The reason `AutomationRun` exists at all: "why did my task change" and "why
 * didn't my rule fire" are the first two questions anyone asks of a rules
 * engine, and neither is answerable without a per-step record.
 *
 * So this shows every step, not just the outcome — including the ones that were
 * skipped, because a rule that fired and did nothing is the usual case and the
 * gated condition is the explanation.
 *
 * Readable by any space member, not just admins: the person whose task moved is
 * usually not the person who wrote the rule.
 */
const AutomationRunLog = ({ automationId }: Props) => {
  const query = useQuery({
    queryKey: ["automation_runs", automationId],
    queryFn: () =>
      apiGet<{ runs: AutomationRun[] }>(`/automations/${automationId}/runs`, {
        errorMessage: "Failed to load the run history.",
      }),
  });

  if (query.isLoading) {
    return <Skeleton className="h-32 rounded-md bg-muted/40" />;
  }

  const runs = query.data?.runs ?? [];

  if (runs.length === 0) {
    return (
      <Card className="px-4 py-8 text-center">
        <CircleSlash className="mx-auto mb-2 h-5 w-5 text-muted-foreground" />
        <p className="text-sm font-medium">This rule hasn&apos;t run yet</p>
        <p className="mt-1 text-xs text-muted-foreground">
          Runs appear here as tasks on this board change.
        </p>
      </Card>
    );
  }

  return (
    <div className="space-y-2">
      {runs.map((run) => {
        const status = STATUS_META[run.status];
        return (
          <Card key={run.id} className="p-3" data-testid={`run-${run.id}`}>
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant={status.variant} className="text-[10px]">
                {status.label}
              </Badge>
              <span className="truncate text-sm font-medium">{run.taskTitle}</span>
              {run.depth > 0 && (
                // A non-zero depth means another rule caused this — the first
                // thing to look at when rules appear to be fighting.
                <Badge variant="secondary" className="text-[10px]">
                  triggered by another rule
                </Badge>
              )}
              <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                {when(run.ranAt)}
              </span>
            </div>

            {run.steps.length > 0 && (
              <ul className="mt-2 space-y-1 border-l pl-3">
                {run.steps.map((step, i) => {
                  const meta = STEP_META[step.outcome];
                  const Icon = meta.icon;
                  return (
                    <li key={`${run.id}-${i}`} className="flex items-start gap-2 text-xs">
                      <Icon className={`mt-0.5 h-3 w-3 shrink-0 ${meta.tone}`} />
                      <span className="text-muted-foreground">
                        {KIND_LABEL[step.kind]} {meta.label}:
                      </span>
                      <span className={step.outcome === "error" ? "text-destructive" : ""}>
                        {step.detail}
                      </span>
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        );
      })}
    </div>
  );
};

export default AutomationRunLog;
