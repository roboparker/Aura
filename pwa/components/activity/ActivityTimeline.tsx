import { cn } from "@/lib/utils";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";

/**
 * Responsive "history point graph" for activity streams. Renders a vertical
 * rail of point markers with one event card per entry.
 *
 *  - When the container is wide enough (`@2xl`, ~42rem) the rail sits in the
 *    middle and cards alternate to either side.
 *  - When it's narrow the rail moves to the left and every card stacks on the
 *    right — so it works inside the task drawer as well as a full-width tab.
 *
 * It's a presentational component: the caller resolves each event's actor and
 * change summary and passes them in. Layout responds to its own width via CSS
 * container queries, so the same component adapts wherever it's mounted.
 */
export type TimelineActor = AvatarUser & { "@id"?: string };

export interface TimelineEvent {
  id: number;
  action: string;
  loggedAt: string;
  objectClass: string;
  actor: TimelineActor | null;
  /** Pre-rendered "field: value, …" summary, or null. */
  changes?: string | null;
}

export interface ActivityTimelineProps {
  events: TimelineEvent[];
  className?: string;
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

/** Human label for a Loggable entity short-name. */
const objectLabel = (objectClass: string): string => {
  switch (objectClass) {
    case "CustomFieldDefinition":
      return "custom field";
    default:
      return objectClass.toLowerCase();
  }
};

/** Marker colour by action, so the rail reads as a status graph at a glance. */
const dotClass = (action: string): string => {
  switch (action) {
    case "create":
      return "bg-emerald-500";
    case "remove":
      return "bg-destructive";
    case "update":
      return "bg-sky-500";
    default:
      return "bg-muted-foreground";
  }
};

const ActivityTimeline = ({ events, className }: ActivityTimelineProps) => {
  return (
    <ol
      className={cn("@container relative space-y-0", className)}
      data-testid="activity-timeline"
    >
      {events.map((event, index) => {
        const onLeft = index % 2 === 0;
        const name = event.actor ? displayName(event.actor) : "Someone";
        return (
          <li
            key={event.id}
            className={cn(
              "grid items-start gap-x-3 pb-6",
              // Narrow: [rail | card]. Wide: [card | rail | card].
              "grid-cols-[1.5rem_minmax(0,1fr)]",
              "@2xl:grid-cols-[minmax(0,1fr)_1.5rem_minmax(0,1fr)]",
            )}
            data-testid="activity-timeline-item"
          >
            {/* Rail: continuous connecting line + the point marker. */}
            <div
              aria-hidden
              className="relative col-start-1 flex h-full justify-center @2xl:col-start-2"
            >
              <span className="absolute top-1 bottom-[-1.5rem] w-px bg-border" />
              <span
                className={cn(
                  "relative z-10 mt-1 h-3 w-3 shrink-0 rounded-full ring-4 ring-background",
                  dotClass(event.action),
                )}
              />
            </div>

            {/* Card: always the right column when narrow; alternates when wide. */}
            <div
              className={cn(
                "col-start-2 min-w-0",
                onLeft
                  ? "@2xl:col-start-1 @2xl:row-start-1 @2xl:text-right"
                  : "@2xl:col-start-3 @2xl:row-start-1",
              )}
            >
              <div
                className={cn(
                  "inline-flex max-w-full items-start gap-2 rounded-lg border bg-card px-3 py-2 text-sm",
                  onLeft && "@2xl:flex-row-reverse @2xl:text-left",
                )}
              >
                {event.actor ? (
                  <UserAvatar user={event.actor} size="sm" />
                ) : (
                  <span
                    aria-hidden
                    className="h-8 w-8 shrink-0 rounded-full bg-muted"
                  />
                )}
                <div className="min-w-0">
                  <p className="leading-snug">
                    <span className="font-medium">{name}</span>{" "}
                    <span className="text-muted-foreground">
                      {verbFor(event.action)} this {objectLabel(event.objectClass)}
                      {event.changes && event.action !== "remove"
                        ? ` — ${event.changes}`
                        : ""}
                    </span>
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {formatRelative(event.loggedAt)}
                  </p>
                </div>
              </div>
            </div>
          </li>
        );
      })}
    </ol>
  );
};

export default ActivityTimeline;
