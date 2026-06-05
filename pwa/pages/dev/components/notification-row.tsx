import NotificationRow from "@/components/notifications/NotificationRow";
import type { AppNotification } from "@/lib/notificationTypes";
import ComponentDoc from "@/components/dev/ComponentDoc";

const actor = {
  givenName: "Lin",
  familyName: "Castellano",
  email: "lin@example.com",
  personalizedColor: "#b45309",
};

const base: Omit<AppNotification, "readAt"> = {
  "@id": "/notifications/1",
  id: "1",
  type: "comment",
  title: "Lin commented on Final hero photography selects",
  body: "Pulled my top three. Adding contact sheets to the attachments — pls take a look on a real screen.",
  actor,
  targetUrl: "/tasks",
  targetLabel: "Final hero photography selects",
  contextLabel: "Spring Campaign Launch",
  archivedAt: null,
  createdAt: new Date(Date.now() - 24 * 60_000).toISOString(),
};

// Hoisted to module scope so the example doesn't call Date.now() during
// render (react-hooks/purity).
const READ_AT = new Date(Date.now() - 11 * 3600_000).toISOString();

const noop = () => {};

const NotificationRowPage = () => (
  <ComponentDoc
    name="NotificationRow"
    description="One inbox row in three states — unread (accent stripe + dot, full color), read (dimmed, no rail), and selected (filled checkbox + tint). Quick actions (mark read / archive / open) slide in on hover. Clicking the row opens the linked entity. Per-type color + badge come from NOTIFICATION_META in lib/notificationTypes."
    importPath={`import NotificationRow from "@/components/notifications/NotificationRow";`}
    examples={[
      {
        title: "Three states",
        code: `<NotificationRow notification={unread} onOpen={...} onMarkRead={...} onArchive={...} />
<NotificationRow notification={read} onOpen={...} />
<NotificationRow notification={n} selectable selected onToggleSelect={...} onOpen={...} />`,
        preview: (
          <ul className="w-full max-w-2xl overflow-hidden rounded-lg border border-border bg-background">
            <NotificationRow
              notification={{ ...base, readAt: null }}
              onMarkRead={noop}
              onArchive={noop}
              onOpen={noop}
            />
            <NotificationRow
              notification={{
                ...base,
                "@id": "/notifications/2",
                id: "2",
                type: "assigned",
                title: "Devin assigned you Email sequence — 5-touch nurture",
                body: null,
                readAt: READ_AT,
              }}
              onOpen={noop}
            />
            <NotificationRow
              notification={{ ...base, "@id": "/notifications/3", id: "3", readAt: null }}
              selectable
              selected
              onToggleSelect={noop}
              onMarkRead={noop}
              onArchive={noop}
              onOpen={noop}
            />
          </ul>
        ),
      },
    ]}
  />
);

export default NotificationRowPage;
