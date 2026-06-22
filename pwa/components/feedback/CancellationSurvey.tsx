import { cn } from "@/lib/utils";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  CANCELLATION_REASONS,
  type CancellationReason,
} from "@/lib/cancellationReasons";

/**
 * Shared "why are you leaving?" survey, rendered inside the account
 * deactivate / delete dialogs and the subscription-cancel dialog. A required
 * single-choice reason plus an optional free-text comment. Controlled — the
 * parent owns the state and gates its submit button on `reason` being set.
 */
const CancellationSurvey = ({
  reason,
  comment,
  onReasonChange,
  onCommentChange,
  disabled = false,
  idPrefix = "cancel",
  reasons = CANCELLATION_REASONS,
}: {
  reason: string;
  comment: string;
  onReasonChange: (value: string) => void;
  onCommentChange: (value: string) => void;
  disabled?: boolean;
  idPrefix?: string;
  reasons?: CancellationReason[];
}) => {
  const commentId = `${idPrefix}-comment`;

  return (
    <div className="space-y-4">
      <fieldset className="space-y-2" disabled={disabled}>
        <legend className="text-sm font-medium">
          Help us improve — why are you leaving?
        </legend>
        <div className="space-y-1.5" role="radiogroup">
          {reasons.map((opt) => {
            const id = `${idPrefix}-reason-${opt.value}`;
            const selected = reason === opt.value;
            return (
              <label
                key={opt.value}
                htmlFor={id}
                className={cn(
                  "flex cursor-pointer items-center gap-2.5 rounded-md border px-3 py-2 text-sm transition-colors",
                  selected
                    ? "border-foreground/40 bg-accent"
                    : "border-input hover:bg-accent/50",
                  disabled && "cursor-not-allowed opacity-60",
                )}
              >
                <input
                  type="radio"
                  id={id}
                  name={`${idPrefix}-reason`}
                  value={opt.value}
                  checked={selected}
                  onChange={() => onReasonChange(opt.value)}
                  className="h-4 w-4 accent-foreground"
                  data-testid={id}
                />
                <span>{opt.label}</span>
              </label>
            );
          })}
        </div>
      </fieldset>

      <div className="space-y-1.5">
        <Label htmlFor={commentId}>
          Anything else?{" "}
          <span className="font-normal text-muted-foreground">(optional)</span>
        </Label>
        <Textarea
          id={commentId}
          value={comment}
          onChange={(e) => onCommentChange(e.target.value)}
          placeholder="Tell us more about your experience…"
          rows={3}
          maxLength={2000}
          disabled={disabled}
          data-testid={commentId}
        />
      </div>
    </div>
  );
};

export default CancellationSurvey;
