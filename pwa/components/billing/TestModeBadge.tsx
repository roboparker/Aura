import { FlaskConical } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";

/**
 * "TEST MODE" marker for any surface that can start a payment (#stripe-mode).
 *
 * The API derives the flag from the Stripe key prefix (`sk_test_` vs
 * `sk_live_`), so it can't drift from what Stripe will actually do. Amber, not
 * red — a sandbox isn't broken, it just isn't real money — but loud enough that
 * nobody reads a test-card charge as a real one.
 *
 * Render nothing when the instance is live: a badge that's always present stops
 * being read.
 */
const TestModeBadge = ({
  testMode,
  className,
}: {
  testMode: boolean | undefined;
  className?: string;
}) => {
  if (testMode !== true) return null;

  return (
    <Badge
      variant="outline"
      title="This instance is connected to a Stripe sandbox — no real money moves."
      className={cn(
        "gap-1 border-amber-300 bg-amber-50 text-amber-800 uppercase tracking-wide",
        "dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300",
        className,
      )}
      data-testid="stripe-test-mode-badge"
    >
      <FlaskConical className="h-3 w-3" aria-hidden />
      Test mode
    </Badge>
  );
};

export default TestModeBadge;
