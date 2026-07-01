import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Client for the per-user read-only iCal calendar feed (#calendar-sync). The
 * feed token is stored hashed server-side, so the plaintext subscribe URL is
 * only ever returned once — from {@link regenerateCalendarFeed}. `GET` reports
 * status only.
 */
export interface CalendarFeedStatus {
  enabled: boolean;
  createdAt: string | null;
  lastAccessedAt: string | null;
}

export const fetchCalendarFeedStatus = async (): Promise<CalendarFeedStatus> => {
  const res = await fetch(`${ENTRYPOINT}/me/calendar-feed`, {
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  if (!res.ok) throw new Error("Failed to load calendar feed status.");
  return res.json();
};

/** (Re)generate the feed token; returns the one-shot subscribe URL. */
export const regenerateCalendarFeed = async (): Promise<string> => {
  const res = await fetch(`${ENTRYPOINT}/me/calendar-feed/regenerate`, {
    method: "POST",
    credentials: "include",
    headers: { Accept: "application/json" },
  });
  if (!res.ok) throw new Error("Failed to generate calendar feed URL.");
  const data: { feedUrl?: string } = await res.json();
  if (!data.feedUrl) throw new Error("Feed URL missing from response.");
  return data.feedUrl;
};

export const revokeCalendarFeed = async (): Promise<void> => {
  const res = await fetch(`${ENTRYPOINT}/me/calendar-feed`, {
    method: "DELETE",
    credentials: "include",
  });
  if (!res.ok) throw new Error("Failed to revoke calendar feed.");
};
