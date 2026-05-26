import { useEffect, useState } from "react";
import { Check, Loader2 } from "lucide-react";

interface Props {
  initials: string;
  color: string;
  /** Called once the last step ticks over so the caller can route away. */
  onDone: () => void;
}

interface Step {
  /** Stable id used for keys + data attributes; also the visible label. */
  id: string;
  label: string;
}

/**
 * Steps the user "sees" the system do after sign-up. The real work
 * (user row + personal space) finishes inside the POST /users
 * processor — this component is staging the moment as four discrete
 * beats so the transition to /signin doesn't feel like a hard cut.
 *
 * Timing chosen so the whole thing wraps in well under 3 seconds;
 * anything longer turns a confirmation into a wait.
 */
const STEPS: Step[] = [
  { id: "account", label: "created account" },
  { id: "color", label: "saved avatar color" },
  { id: "space", label: "provisioning private space…" },
  { id: "workspace", label: "opening workspace" },
];

const STEP_DELAY_MS = 600;
/** Extra pause after the last step ticks before the parent routes away. */
const TRAILING_PAUSE_MS = 800;

const SignUpProvisioning = ({ initials, color, onDone }: Props) => {
  // Number of steps that have already flipped from "pending" to "done".
  // Walks 0..STEPS.length, then onDone() fires.
  const [completed, setCompleted] = useState(0);

  useEffect(() => {
    if (completed >= STEPS.length) {
      const id = window.setTimeout(onDone, TRAILING_PAUSE_MS);
      return () => window.clearTimeout(id);
    }
    const id = window.setTimeout(() => setCompleted((c) => c + 1), STEP_DELAY_MS);
    return () => window.clearTimeout(id);
  }, [completed, onDone]);

  return (
    <div className="space-y-5" data-testid="signup-provisioning">
      <div className="flex flex-col items-center gap-3">
        <div className="relative">
          <div
            className="flex h-16 w-16 items-center justify-center rounded-md text-xl font-semibold text-white"
            style={{ backgroundColor: color }}
            aria-hidden
          >
            {initials}
          </div>
          <span className="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full bg-primary text-primary-foreground ring-2 ring-background">
            <Check className="h-3.5 w-3.5" aria-hidden />
          </span>
        </div>
        <div className="text-center">
          <p className="text-lg font-semibold">Your account is ready</p>
          <p className="text-sm text-muted-foreground">
            Setting up your Private space…
          </p>
        </div>
      </div>

      <ul className="space-y-1.5 font-mono text-xs">
        {STEPS.map((step, idx) => {
          const isDone = idx < completed;
          const isActive = idx === completed;
          return (
            <li
              key={step.id}
              data-testid="signup-provisioning-step"
              data-step-id={step.id}
              data-state={isDone ? "done" : isActive ? "active" : "pending"}
              className={[
                "flex items-center gap-2",
                isDone
                  ? "text-foreground"
                  : isActive
                    ? "text-muted-foreground"
                    : "text-muted-foreground/50",
              ].join(" ")}
            >
              {isDone ? (
                <Check className="h-3 w-3 text-primary" aria-hidden />
              ) : isActive ? (
                <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
              ) : (
                <span
                  className="h-3 w-3 rounded-full border border-current opacity-50"
                  aria-hidden
                />
              )}
              <span>{step.label}</span>
            </li>
          );
        })}
      </ul>
    </div>
  );
};

export default SignUpProvisioning;
