# Madori

Madori is an API-first task and project management app. The backend is a Symfony / [API Platform](https://api-platform.com) service; the frontend is a Next.js PWA. The two run side-by-side behind FrankenPHP in development.

## Features

- **Projects** with markdown descriptions and a detail view.
- **Tasks** that are inline-editable in a table, draggable to reorder, with due dates, assignees, status filters, and a personal *My Tasks* view.
- **Groups** — reusable, owner-managed sets of users with email-based invitations. Invitees get a signup link and are auto-joined on registration.
- **Accounts** — sign up / sign in on a unified page, verified email-change flow, password reset, profile pictures, and personalized avatar colors.
- **Real-time updates** via Mercure (server-sent events) on collaborative resources.
- **Auto-generated admin UI** at `/admin` via `@api-platform/admin`.

## Repository layout

```
api/    Symfony 7 / API Platform 4 backend (PHP 8.4, PostgreSQL 16, Doctrine, Mercure)
pwa/    Next.js 15 frontend (TypeScript, Tailwind v4, shadcn/ui, TanStack Query, Formik, BlockNote)
e2e/    Playwright end-to-end tests
helm/   Kubernetes / Helm deployment charts
docs/   Architecture, API guide, branching, deployment
```

## Quick start

Requires Docker and Docker Compose.

```bash
docker compose up -d
```

The app is served at [https://localhost](https://localhost). FrankenPHP terminates TLS, serves the API at `/api/*`, and proxies the PWA at the root.

### Running multiple worktrees

To bring up a second stack alongside the main checkout without port collisions:

```bash
scripts/worktree-env.sh    # writes ./.env with a unique project name and port block
docker compose up -d
```

See [`docs/developer/deployment.md`](docs/developer/deployment.md) for details.

## Local development

### API

```bash
cd api
composer install
bin/console doctrine:migrations:migrate
bin/phpunit
```

### PWA

```bash
cd pwa
pnpm install
pnpm dev      # http://localhost:3000
pnpm lint
```

### End-to-end

```bash
cd e2e
npm install
npx playwright test
```

## Documentation

Developer:
- [Architecture](docs/developer/architecture.md) — how the API, PWA, and Mercure fit together.
- [API guide](docs/developer/api-guide.md) — entity, resource, and serialization conventions.
- [Branching and releases](docs/developer/branching-and-releases.md) — branch naming, conventional commits, release tags.
- [Deployment](docs/developer/deployment.md) — Docker, Helm, and parallel-worktree setup.
- [MCP server](docs/developer/mcp-server.md) — `POST /mcp` endpoint, token auth, tool catalog.
- [Two-factor auth](docs/developer/two-factor-auth.md) — TOTP, recovery codes, lost-device flow.
- [Password reset](docs/developer/password-reset.md) — forgot/reset flow, token model, enumeration mitigations.

End-user guides:
- [Two-factor auth](docs/user/two-factor-auth.md) — how to enable, sign in with, and recover 2FA.
- [Password reset](docs/user/password-reset.md) — how to reset a forgotten password.

Community:
- [Contributing](.github/CONTRIBUTING.md), [Code of Conduct](CODE_OF_CONDUCT.md), [Security](.github/SECURITY.md).

## License

Copyright © 2026 Robert Parker.

Madori is free software, licensed under the **GNU Affero General Public License
v3.0** — see [`LICENSE`](LICENSE). The AGPL's network-use clause means anyone
who runs a modified Madori as a network service must offer their users the
corresponding source.

Separate **commercial licenses** are available from the maintainer for parties
who do not wish to comply with the AGPL. Contributions are accepted under the
[Contributor License Agreement](CLA.md), which supports this dual-licensing
model.
