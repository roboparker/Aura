import { ENTRYPOINT } from "@/config/entrypoint";

/**
 * Web Push (#100) frontend glue: register the service worker, subscribe via the
 * VAPID public key, and hand the subscription to `POST /me/push-subscriptions`.
 * The backend (WebPushSender) then delivers reminder/notification payloads even
 * when the tab is closed; the service worker renders them.
 */
export const isPushSupported = (): boolean =>
  typeof window !== "undefined" &&
  "serviceWorker" in navigator &&
  "PushManager" in window &&
  "Notification" in window;

export const pushPermission = (): NotificationPermission | "unsupported" =>
  isPushSupported() ? Notification.permission : "unsupported";

const urlBase64ToUint8Array = (base64: string): Uint8Array<ArrayBuffer> => {
  const padding = "=".repeat((4 - (base64.length % 4)) % 4);
  const normalized = (base64 + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(normalized);
  const out = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i += 1) out[i] = raw.charCodeAt(i);
  return out;
};

/** Register the push service worker. Idempotent; safe on every load. */
export const registerPushServiceWorker = async (): Promise<void> => {
  if (!isPushSupported()) return;
  try {
    await navigator.serviceWorker.register("/sw.js");
  } catch {
    /* a failed registration shouldn't break the app */
  }
};

export type EnablePushResult =
  | { ok: true }
  | { ok: false; reason: "unsupported" | "no-vapid" | "denied" | "failed" };

/**
 * Request notification permission, subscribe with the VAPID key, and register
 * the subscription with the API. Returns a reason on failure so the caller can
 * show the right message. Does NOT flip the `pushNotificationsEnabled`
 * preference — the caller owns that (so the optimistic UI stays in one place).
 */
export const enablePush = async (): Promise<EnablePushResult> => {
  if (!isPushSupported()) return { ok: false, reason: "unsupported" };
  const vapid = process.env.NEXT_PUBLIC_VAPID_PUBLIC_KEY;
  if (!vapid) return { ok: false, reason: "no-vapid" };

  const permission = await Notification.requestPermission();
  if (permission !== "granted") return { ok: false, reason: "denied" };

  try {
    const registration = await navigator.serviceWorker.register("/sw.js");
    await navigator.serviceWorker.ready;
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapid),
      });
    }
    const keys = subscription.toJSON().keys ?? {};
    const res = await fetch(`${ENTRYPOINT}/me/push-subscriptions`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/ld+json" },
      body: JSON.stringify({
        endpoint: subscription.endpoint,
        p256dh: keys.p256dh,
        auth: keys.auth,
      }),
    });
    // 422 = this endpoint is already registered (unique constraint) — fine, the
    // device is subscribed either way.
    if (!res.ok && res.status !== 422) return { ok: false, reason: "failed" };
    return { ok: true };
  } catch {
    return { ok: false, reason: "failed" };
  }
};
