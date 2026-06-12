# Analytics (self-hosted Umami)

Madori ships with optional, self-hosted, privacy-first analytics powered by
[Umami](https://umami.is/). It is cookieless (no consent banner required),
honors Do Not Track, stores everything on our own infrastructure, and is
served first-party so it survives most ad blockers. Google Analytics was
deliberately avoided: it requires a consent banner for EU visitors, loses a
large share of a technical audience to ad blockers, and sits poorly with a
product whose core concept is a private space.

## Architecture

```
browser ── GET  /umami/script.js ──┐
        ── POST /umami/api/send ───┤  Caddy (php container) ── reverse_proxy ──> umami:3000
                                   │
operator ── SSH tunnel ── 127.0.0.1:${UMAMI_PORT:-3001} ──> umami dashboard
```

- **`umami` compose service** (`ghcr.io/umami-software/umami:postgresql-latest`)
  runs next to the app and uses its **own `umami` database** on the shared
  Postgres instance. Umami applies its own migrations on boot; its tables
  never touch the `app` database, so Doctrine and the schema CI check are
  unaffected.
- **First-party proxy**: Caddy (`api/frankenphp/Caddyfile`) exposes exactly
  two paths — `/umami/script.js` (the tracker) and `/umami/api/send` (the
  collect endpoint). The tracker derives its endpoint from the script path,
  so everything stays on the app origin. The dashboard is *not* reachable
  through the public host.
- **Dashboard access**: the dashboard gets its own hostname, served by the
  same Caddy — set `UMAMI_SERVER_NAME=analytics.<domain>` in the server's
  `.env` and point a DNS A record at the server; Caddy provisions TLS
  automatically. Auth is Umami's own login, so the admin password must be
  strong — this host is internet-reachable. The umami service also keeps a
  loopback-bound published port (`127.0.0.1:${UMAMI_PORT:-3001}`) as an
  SSH-tunnel fallback (`ssh -L 3001:localhost:3001 <server>`).
- **Compose profile**: the service sits behind the `analytics` profile so CI
  stacks and a casual dev `docker compose up -d` don't boot it.
  `scripts/deploy.sh` exports `COMPOSE_PROFILES=analytics`, so production
  always runs it.

### Database provisioning

- **Production** — `scripts/deploy.sh` ensures the `umami` database exists
  idempotently on every deploy (best-effort: a failure logs a warning, it
  never blocks the app deploy).
- **Local** — create it once by hand:

  ```bash
  docker compose exec database createdb -U app umami
  ```

## Running locally

```bash
# one-off
docker compose --profile analytics up -d

# or persistently, in your .env
COMPOSE_PROFILES=analytics
```

Create the `umami` database with the one-liner above if it doesn't exist yet
(umami crash-loops until it does), then let the umami container come up. The
dashboard is
at http://localhost:3001 (or `UMAMI_PORT` from `scripts/worktree-env.sh` in a
linked worktree). Default credentials are **admin / umami** — change the
password immediately, even locally.

To see tracking in dev, set `NEXT_PUBLIC_UMAMI_WEBSITE_ID` (from the website
you create in the dashboard) in `pwa/.env.development.local` (gitignored).
Analytics is off whenever that variable is unset — which is the dev default,
so the suite and local work never emit events.

## Production setup (one-time)

1. Add `UMAMI_APP_SECRET=<long random string>` to the server's `.env`
   (`compose.prod.yaml` hard-requires it; generate with `openssl rand -hex 32`).
2. Deploy (the next promote PR brings the umami service up and creates its
   database).
3. Set `UMAMI_SERVER_NAME=analytics.<domain>` in the server's `.env` and add
   the matching DNS A record (or tunnel in: `ssh -L 3001:localhost:3001
   <server>` → http://localhost:3001). Log in as **admin / umami** and
   change the password immediately.
4. Settings → Websites → Add website (name it after the domain). Copy the
   **Website ID**.
5. Set `NEXT_PUBLIC_UMAMI_WEBSITE_ID=<id>` in `pwa/.env.production` and merge
   — the ID is public (it ships in the page source), so committing it is
   fine. The next deploy bakes it into the PWA build and tracking goes live.

## PWA integration

- `pwa/lib/analytics.ts` — the only API call sites use:
  `trackEvent(name, data?)`. It no-ops when the tracker is absent (dev,
  tests, ad-blocked clients), so callers never guard.
- `pwa/pages/_app.tsx` injects the tracker `<Script>` only when
  `NEXT_PUBLIC_UMAMI_WEBSITE_ID` is set. Pageviews (including SPA route
  changes) are tracked automatically; `data-do-not-track` is enabled.

### Event catalog

Event names are kebab-case verbs of the thing that happened. Payloads stay
coarse (booleans, counts, category names) — **never user content, titles, or
emails**.

| Event | Fired from | Data |
| --- | --- | --- |
| `signup` | `AuthContext.register` | `invited` (came via invite link) |
| `task-create` | `pages/tasks.tsx` | — |
| `project-create` | `pages/projects/index.tsx` | — |
| `page-create` | `pages/pages/index.tsx` | — |
| `discussion-create` | `DiscussionsPanel` | `category` |
| `comment-create` | `CommentsPanel` (all surfaces) | — |
| `space-create` | `pages/spaces/new.tsx` | `visibility` |
| `group-create` | `pages/groups/new.tsx` | — |

To add an event: call `trackEvent("thing-happened", {...})` after the action
succeeds (post-2xx, not optimistically), keep the payload coarse, and add a
row to this table.

## Privacy posture

- Cookieless and anonymized by design — Umami stores no PII and needs no
  consent banner ([umami.is/privacy](https://umami.is/privacy)).
- `data-do-not-track="true"`: browsers sending DNT are not tracked at all.
- All data stays on our own server, in our own Postgres; nothing is shared
  with third parties.
- Event payloads carry no user content (enforced by convention — see the
  event catalog above).
