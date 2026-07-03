import { useCallback, useEffect, useState } from "react";
import { Calendar, Check, Copy, RefreshCw, Trash2 } from "lucide-react";
import {
  fetchCalendarFeedStatus,
  regenerateCalendarFeed,
  revokeCalendarFeed,
  type CalendarFeedStatus,
} from "@/lib/calendarFeed";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

const formatDate = (iso: string | null): string =>
  iso ? new Date(iso).toLocaleString() : "—";

/**
 * Settings → Calendar sync card. Reveals, (re)generates, and revokes the
 * private read-only iCal subscribe URL. Because the token is stored hashed,
 * the plaintext URL is shown exactly once (right after generation); on a later
 * visit we can only report that a feed is active and offer Regenerate/Revoke.
 */
const CalendarSyncCard = () => {
  const [status, setStatus] = useState<CalendarFeedStatus | null>(null);
  const [feedUrl, setFeedUrl] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isBusy, setIsBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      setStatus(await fetchCalendarFeedStatus());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load status.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const regenerate = async () => {
    setIsBusy(true);
    setError(null);
    try {
      const url = await regenerateCalendarFeed();
      setFeedUrl(url);
      setCopied(false);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to generate URL.");
    } finally {
      setIsBusy(false);
    }
  };

  const revoke = async () => {
    if (
      !window.confirm(
        "Revoke the calendar feed? Any calendar subscribed to it will stop updating.",
      )
    ) {
      return;
    }
    setIsBusy(true);
    setError(null);
    try {
      await revokeCalendarFeed();
      setFeedUrl(null);
      await load();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to revoke feed.");
    } finally {
      setIsBusy(false);
    }
  };

  const copy = async () => {
    if (!feedUrl) return;
    try {
      await navigator.clipboard.writeText(feedUrl);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setError("Couldn't copy to clipboard — select and copy the URL manually.");
    }
  };

  const enabled = status?.enabled ?? false;

  return (
    <div className="space-y-4" data-testid="calendar-sync">
      <div className="flex items-start gap-3">
        <div className="mt-0.5 rounded-md bg-muted p-2 text-muted-foreground">
          <Calendar className="h-5 w-5" />
        </div>
        <div className="space-y-1">
          <p className="text-sm text-muted-foreground">
            Subscribe to a private, read-only feed of your tasks (those with a
            due date, that you own or are assigned) in Google, Outlook, or Apple
            Calendar. The feed is one-way — edits in your calendar app don&apos;t
            change your tasks.
          </p>
        </div>
      </div>

      {error && (
        <Alert variant="destructive">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      )}

      {isLoading ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="space-y-4">
          {feedUrl ? (
            <div className="space-y-2 rounded-md border border-cyan-700/30 bg-cyan-700/5 p-4">
              <p className="text-sm font-medium">Your subscribe URL</p>
              <div className="flex gap-2">
                <Input
                  readOnly
                  value={feedUrl}
                  onFocus={(e) => e.currentTarget.select()}
                  className="font-mono text-xs"
                  data-testid="calendar-feed-url"
                  aria-label="Calendar subscribe URL"
                />
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => void copy()}
                  data-testid="calendar-feed-copy"
                >
                  {copied ? (
                    <>
                      <Check className="h-4 w-4" /> Copied
                    </>
                  ) : (
                    <>
                      <Copy className="h-4 w-4" /> Copy
                    </>
                  )}
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                Copy this now — for your security it won&apos;t be shown again.
                Anyone with this link can view your task schedule, so keep it
                private. Lost it? Just regenerate.
              </p>
            </div>
          ) : enabled ? (
            <Alert>
              <AlertDescription>
                A calendar feed is active (created {formatDate(status?.createdAt ?? null)}
                {status?.lastAccessedAt
                  ? `, last synced ${formatDate(status.lastAccessedAt)}`
                  : ""}
                ). The subscribe URL is only shown once — regenerate to get a new
                link (which replaces the old one).
              </AlertDescription>
            </Alert>
          ) : (
            <p className="text-sm text-muted-foreground">
              No calendar feed yet. Generate a private subscribe URL to get
              started.
            </p>
          )}

          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              onClick={() => void regenerate()}
              disabled={isBusy}
              data-testid="calendar-feed-regenerate"
            >
              <RefreshCw className="h-4 w-4" />
              {enabled ? "Regenerate URL" : "Generate URL"}
            </Button>
            {enabled && (
              <Button
                type="button"
                variant="ghost"
                className="text-destructive hover:text-destructive"
                onClick={() => void revoke()}
                disabled={isBusy}
                data-testid="calendar-feed-revoke"
              >
                <Trash2 className="h-4 w-4" /> Revoke
              </Button>
            )}
          </div>

          <div className="rounded-md border bg-muted/30 p-4 text-sm">
            <p className="font-medium">How to subscribe</p>
            <ul className="mt-2 space-y-1.5 text-muted-foreground">
              <li>
                <span className="font-medium text-foreground">Google Calendar:</span>{" "}
                Other calendars → <span className="italic">From URL</span> → paste
                the link.
              </li>
              <li>
                <span className="font-medium text-foreground">Outlook:</span> Add
                calendar → <span className="italic">Subscribe from web</span> →
                paste the link.
              </li>
              <li>
                <span className="font-medium text-foreground">Apple Calendar:</span>{" "}
                File → <span className="italic">New Calendar Subscription</span> →
                paste the link.
              </li>
            </ul>
            <p className="mt-2 text-xs text-muted-foreground">
              Calendar apps refresh subscriptions on their own schedule (often
              every few hours), so new tasks may take a little while to appear.
            </p>
          </div>
        </div>
      )}
    </div>
  );
};

export default CalendarSyncCard;
