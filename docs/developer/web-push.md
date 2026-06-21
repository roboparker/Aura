# Web Push notifications (#100)

Web Push delivers notifications (task reminders, mentions, comments, status changes) to a user's browser even when the tab is closed. The backend signs payloads with a VAPID key pair and POSTs them to each subscribed device's push service; a service worker in the PWA renders them.

## Moving parts

- **`PushSubscription`** (entity) — one row per subscribed device, holding the push service `endpoint` and the `p256dh` / `auth` keys, owned by a user. Managed via `GET` / `POST` / `DELETE /me/push-subscriptions` (POST is idempotent on the endpoint — a re-subscribe 422s on the unique constraint, which the client treats as "already subscribed").
- **`App\Push\WebPushSender`** — signs and sends a `PushPayload` to a subscription via `minishlink/web-push`, using the VAPID env keys. **No-ops with a logged warning when the keys are blank**, so a fresh install doesn't error. Endpoints the push service rejects with `404`/`410` are pruned inline so dead subscriptions don't accumulate.
- **`pwa/public/sw.js`** — the service worker. On `push` it renders the `{title, body, url, tag}` payload as a desktop notification; on `notificationclick` it focuses an existing tab on the URL or opens a new one. Registered once from `_app.tsx`.
- **`pwa/lib/push.ts`** — `enablePush()` runs the browser opt-in: `Notification.requestPermission()` → `PushManager.subscribe({ applicationServerKey })` → `POST /me/push-subscriptions`. Plus `isPushSupported()` / `pushPermission()` helpers.
- **`EnablePushPrompt`** (`pwa/components/notifications/EnablePushPrompt.tsx`) — a small amber nudge shown in the `RemindersEditor` when a task has reminders but the browser isn't subscribed, with an inline "Turn on". Its "Not now" dismiss is browser-session only and **never** disables the account-level `pushNotificationsEnabled` preference (so dismissing on a shared/public computer doesn't kill push on the user's own devices).

## Delivery

`App\Service\NotificationDispatcher` (and the reminder cron `app:tasks:reminders:dispatch`) fan each event out to every registered device of a recipient who has `pushNotificationsEnabled = true` (the **default**) and isn't in quiet hours, reusing `WebPushSender`. Push runs alongside the in-app bell (Mercure) and email — see the **Notifications** entry in `CLAUDE.md`.

## VAPID keys

The application-server key pair is split by sensitivity:

| Var | Side | Notes |
| --- | --- | --- |
| `VAPID_PUBLIC_KEY` | API | Base64url P-256 public key. **Must equal `NEXT_PUBLIC_VAPID_PUBLIC_KEY`.** |
| `VAPID_PRIVATE_KEY` | API | Base64url P-256 private key. **Secret** — server `.env` only, never the repo. |
| `VAPID_SUBJECT` | API | `mailto:` or `https://` contact for the push service to reach you. |
| `NEXT_PUBLIC_VAPID_PUBLIC_KEY` | PWA | The same public key, read by `PushManager.subscribe()`. Baked into the PWA at build time; committed in `pwa/.env.production` (not secret — it ships to every browser). |

The API reads the three `VAPID_*` vars via `%env(default::VAPID_*)%` in `WebPushSender`.

### Generating and setting the keys

Generate a P-256 key pair (anywhere with the `web-push` CLI, or on the server via the bundled `minishlink/web-push`):

```bash
npx web-push generate-vapid-keys
# Public Key:  B....
# Private Key: ....
```

Then:

- Put the **public** key in `pwa/.env.production` as `NEXT_PUBLIC_VAPID_PUBLIC_KEY` (committed; baked into the build at CI).
- Put **all three** in the server's untracked `/opt/aura/.env` (`VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT`). `compose.yaml` wires them to the `php` **and** `worker` services via `${VAPID_*}` references (the worker sends reminder/digest push, so it needs them too).
- The public key must match on both sides, or browsers can't validate the subscription.

Until the keys are set, `WebPushSender` no-ops (logging a warning) and the PWA's enable prompts stay hidden — everything else works normally.

Web Push requires HTTPS (the Service Worker and Push APIs do). See `docs/developer/deployment.md` § Web Push for the production env-var table.
