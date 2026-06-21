import { cn } from "@/lib/utils";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { displayName } from "@/lib/userDisplay";

/**
 * Responsive "history point graph" for activity streams. Renders a vertical
 * rail of point markers with one event card each.
 *
 *  - When the container is wide enough (`@2xl`, ~42rem) the rail sits in the
 *    middle and cards alternate to either side.
 *  - When it's narrow the rail moves to the left and every card stacks on the
 *    right — so it works inside the task drawer as well as a full-width tab.
 *
 * The rail line and dots are positioned at exactly 50% of the (full-width) row,
 * so the centre never drifts regardless of card widths. It's presentational:
 * the caller resolves each event's actor and change summary.
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
      className={cn("@container relative w-full", className)}
      data-testid="activity-timeline"
    >
      {events.map((event, index) => {
        const onLeft = index % 2 === 0;
        const name = event.actor ? displayName(event.actor) : "Someone";
        return (
          <li
            key={event.id}
            className="relative pb-6"
            data-testid="activity-timeline-item"
          >
            {/* Connecting line: at the left when narrow, dead-centre when wide. */}
            <span
              aria-hidden
              className="absolute inset-y-0 left-[0.5625rem] w-px -translate-x-1/2 bg-border @2xl:left-1/2"
            />
            {/* Point marker, sharing the line's x so they always align. */}
            <span
              aria-hidden
              className={cn(
                "absolute top-1.5 left-[0.5625rem] z-10 h-3 w-3 -translate-x-1/2 rounded-full ring-4 ring-background @2xl:left-1/2",
                dotClass(event.action),
              )}
            />

            {/* Card: right of the line when narrow; alternating halves when wide. */}
            <div
              className={cn(
                "pl-6 @2xl:pl-0",
                onLeft
                  ? "@2xl:pr-[calc(50%+1rem)] @2xl:text-right"
                  : "@2xl:pl-[calc(50%+1rem)]",
              )}
            >
              <div
                className={cn(
                  "inline-flex max-w-full items-start gap-2 rounded-lg border bg-card px-3 py-2 text-left text-sm",
                  onLeft && "@2xl:flex-row-reverse",
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
                  <p className="leading-snug break-words">
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
