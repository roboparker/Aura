/**
 * Shared vocabulary for the deletion grace period (organizations, spaces,
 * accounts). Mirrors `App\Deletion\SoftDeletionService` on the API side — the
 * two are joined only by these strings, so keep them in step.
 */

export type DeletionTargetType = "organization" | "space" | "account";

/** Statuses `GET /restore/{token}` can report. */
export type RestoreStatus =
  | "ready"
  | "used"
  | "expired"
  | "gone"
  | "active"
  | "restored";

export interface RestoreTokenState {
  status: RestoreStatus;
  targetType: DeletionTargetType;
  label: string;
  expiresAt: string;
}

/** Anything the API reports as being inside its deletion window. */
export interface ScheduledDeletion {
  deletedAt?: string | null;
  purgeAfter?: string | null;
}

export const isScheduledForDeletion = (record: ScheduledDeletion | null | undefined): boolean =>
  Boolean(record?.deletedAt);

export const NOUNS: Record<DeletionTargetType, string> = {
  organization: "organization",
  space: "space",
  account: "account",
};

/**
 * "in 12 days" / "tomorrow" / "today". Deliberately coarse: the exact minute
 * isn't actionable, and a countdown to the hour would read as more precise than
 * the nightly purge actually is.
 */
export const daysUntil = (iso: string | null | undefined): number | null => {
  if (!iso) return null;
  const target = new Date(iso).getTime();
  if (Number.isNaN(target)) return null;
  const ms = target - Date.now();
  return Math.max(0, Math.ceil(ms / 86_400_000));
};

export const formatPurgeDate = (iso: string | null | undefined): string => {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleDateString(undefined, {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
};

/** "12 days left", for the banner's urgency line. */
export const remainingLabel = (purgeAfter: string | null | undefined): string => {
  const days = daysUntil(purgeAfter);
  if (days === null) return "";
  if (days === 0) return "being deleted today";
  if (days === 1) return "1 day left";
  return `${days} days left`;
};
