import { useCallback, useEffect, useState } from "react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { type AvatarUser } from "@/components/user/UserAvatar";
import ActivityTimeline, {
  type TimelineEvent,
} from "@/components/activity/ActivityTimeline";
import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Minimal v1 of the activity timeline (issue #89). Reads the audit
 * feed for either a task or project and renders one entry per row.
 *
 * The feed shape is the same for both endpoints — see
 * `App\Controller\ActivityFeedSerializer`. We resolve the actor IRI
 * via the `actors` map so avatars render without a second roundtrip
 * per row.
 *
 * Filtering and live updates are intentionally absent in v1; if we
 * grow them, this is the place to wire react-query / Mercure.
 */
type ActorRecord = AvatarUser & {
  "@id": string;
  id: string;
  email: string;
};

interface ActivityRow {
  id: number;
  action: "create" | "update" | "remove" | string;
  loggedAt: string;
  objectClass: "Task" | "Project" | string;
  objectId: string;
  version: number;
  actor: string | null;
  data: Record<string, unknown>;
}

interface FeedResponse {
  items: ActivityRow[];
  totalItems: number;
  page: number;
  itemsPerPage: number;
  actors: Record<string, ActorRecord>;
}

export interface ActivityPanelProps {
  /** Endpoint to call: `/tasks/{id}/activity` or `/projects/{id}/activity`.
   *  Built by the parent so this component stays trigger-agnostic. */
  endpoint: string;
}

// "completedOn" → "completed on", "dueDate" → "due date", "maxLength" → "max length".
const humanizeKey = (key: string): string =>
  key
    .replace(/([a-z0-9])([A-Z])/g, "$1 $2")
    .replace(/_/g, " ")
    .toLowerCase();

// Gedmo serialises a versioned association as a bare `{ id }` — a UUID with
// nothing human to show, so we drop those keys from the summary.
const isReference = (value: unknown): boolean =>
  typeof value === "object" &&
  value !== null &&
  "id" in value &&
  Object.keys(value).length === 1;

// PHP `DateTime` serialises as `{ date, timezone_type, timezone }`.
const isPhpDate = (value: unknown): value is { date: string } =>
  typeof value === "object" &&
  value !== null &&
  "date" in value &&
  typeof (value as { date: unknown }).date === "string";

const DATE_FMT = new Intl.DateTimeFormat(undefined, {
  dateStyle: "medium",
  timeStyle: "short",
});

const formatPhpDate = (raw: string): string => {
  // "2026-06-21 00:32:03.354000" (UTC) → a real Date; trim micro→milliseconds.
  const normalized = raw.replace(" ", "T").replace(/(\.\d{3})\d*/, "$1");
  const iso = /([+-]\d{2}:?\d{2}|Z)$/.test(normalized)
    ? normalized
    : `${normalized}Z`;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? raw : DATE_FMT.format(date);
};

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined) return "—";
  if (typeof value === "boolean") return value ? "yes" : "no";
  if (typeof value === "string") return value === "" ? "—" : value;
  if (typeof value === "number") return String(value);
  if (isPhpDate(value)) return formatPhpDate(value.date);
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
};

const renderChanges = (data: Record<string, unknown>): string | null => {
  // List "field: value" for the new state (Gedmo only stores the new value,
  // not the before — surfacing the diff would mean walking adjacent versions).
  const parts = Object.entries(data)
    .filter(([, value]) => !isReference(value))
    .map(([key, value]) => `${humanizeKey(key)}: ${formatValue(value)}`);
  return parts.length === 0 ? null : parts.join(", ");
};

const ActivityPanel = ({ endpoint }: ActivityPanelProps) => {
  const [items, setItems] = useState<ActivityRow[]>([]);
  const [actors, setActors] = useState<Record<string, ActorRecord>>({});
  const [page, setPage] = useState(1);
  const [totalItems, setTotalItems] = useState(0);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(
    async (nextPage: number) => {
      setIsLoading(true);
      setError(null);
      try {
        const url = `${ENTRYPOINT}${endpoint}?page=${nextPage}&itemsPerPage=20`;
        const res = await fetch(url, {
          credentials: "include",
          headers: { Accept: "application/json" },
        });
        if (!res.ok) throw new Error("Failed to load activity.");
        const data: FeedResponse = await res.json();
        setItems((prev) =>
          nextPage === 1 ? data.items : [...prev, ...data.items],
        );
        setActors((prev) => ({ ...prev, ...data.actors }));
        setTotalItems(data.totalItems);
        setPage(data.page);
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to load activity.");
      } finally {
        setIsLoading(false);
      }
    },
    [endpoint],
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const hasMore = items.length < totalItems;

  return (
    <Card data-testid="activity-panel">
      <CardContent className="pt-6">
        <h2 className="text-lg font-semibold mb-3">Activity</h2>
        {error && (
          <Alert variant="destructive" className="mb-3">
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}
        {items.length === 0 && !isLoading ? (
          <p className="text-sm text-muted-foreground italic">
            No activity yet.
          </p>
        ) : (
          <ActivityTimeline
            events={items.map(
              (row): TimelineEvent => ({
                id: row.id,
                action: row.action,
                loggedAt: row.loggedAt,
                objectClass: row.objectClass,
                actor: row.actor ? (actors[row.actor] ?? null) : null,
                changes: renderChanges(row.data),
              }),
            )}
          />
        )}
        {hasMore && (
          <div className="mt-3">
            <Button
              variant="outline"
              size="sm"
              onClick={() => void load(page + 1)}
              disabled={isLoading}
              data-testid="activity-load-more"
            >
              {isLoading ? "Loading…" : "Load more"}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
};

export default ActivityPanel;
