/**
 * Churn-survey reasons shown when a user deactivates/deletes their account or
 * cancels a space subscription. Mirrors the canonical list in
 * `api/src/Entity/CancellationFeedback.php` (REASONS) — keep the two in sync.
 */
export interface CancellationReason {
  value: string;
  label: string;
}

export const CANCELLATION_REASONS: CancellationReason[] = [
  { value: "too_expensive", label: "Too expensive" },
  { value: "missing_features", label: "Missing features I need" },
  { value: "not_using", label: "Not using it enough" },
  { value: "too_complicated", label: "Too hard to use" },
  { value: "switching", label: "Switching to a different tool" },
  { value: "technical_issues", label: "Bugs or technical problems" },
  { value: "other", label: "Other" },
];

/** Body fields sent to the cancellation endpoints alongside any step-up creds. */
export interface CancellationFeedbackBody {
  reason: string;
  comment?: string;
}

/**
 * Build the feedback portion of a cancellation request body. Returns null when
 * no reason has been chosen (the caller should keep the submit button
 * disabled), so callers never POST an invalid survey.
 */
export const cancellationFeedbackBody = (
  reason: string,
  comment: string,
): CancellationFeedbackBody | null => {
  if (!reason) return null;
  const trimmed = comment.trim();
  return trimmed ? { reason, comment: trimmed } : { reason };
};
