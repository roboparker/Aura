import { useCallback, useEffect, useState } from "react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Alert, AlertDescription } from "@/components/ui/alert";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";
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

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });

const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const verbFor = (action: string): string => {
  switch (action) {
    case "create":
      return "created";
    case "update":
      return "updated";
    case "remove":
      return "deleted";
    default:
      return action;
  }
};

const renderChanges = (data: Record<string, unknown>): string | null => {
  const keys = Object.keys(data);
  if (keys.length === 0) return null;
  // For v1 we just list "field: value" — historic values are not in
  // the payload because Gedmo only stores the new state. Surfacing
  // before-state would mean walking adjacent versions, which is a v2
  // concern.
  return keys.map((k) => `${k}: ${formatValue(data[k])}`).join(", ");
};

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined) return "—";
  if (typeof value === "string") return value === "" ? "—" : value;
  if (value instanceof Date) return value.toISOString();
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
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
          <ul className="space-y-3" data-testid="activity-list">
            {items.map((row) => {
              const actor = row.actor ? actors[row.actor] : null;
              const changes = renderChanges(row.data);
              return (
                <li
                  key={row.id}
                  className="flex gap-3 items-start text-sm"
                  data-testid="activity-item"
                >
                  {actor ? (
                    <UserAvatar user={actor} size="sm" />
                  ) : (
                    <span
                      aria-hidden="true"
                      className="h-8 w-8 shrink-0 rounded-full bg-muted"
                    />
                  )}
                  <div className="min-w-0 flex-1">
                    <p>
                      <span className="font-medium">
                        {actor ? displayName(actor) : "Someone"}
                      </span>{" "}
                      <span className="text-muted-foreground">
                        {verbFor(row.action)} this {row.objectClass.toLowerCase()}
                        {changes && row.action !== "remove" ? ` — ${changes}` : ""}
                      </span>
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {formatRelative(row.loggedAt)}
                    </p>
                  </div>
                </li>
              );
            })}
          </ul>
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
