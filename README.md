# Aura

Aura is an API-first task and project management app. The backend is a Symfony / [API Platform](https://api-platform.com) service; the frontend is a Next.js PWA. The two run side-by-side behind FrankenPHP in development.

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

See [`docs/deployment.md`](docs/deployment.md) for details.

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

- [Architecture](docs/architecture.md) — how the API, PWA, and Mercure fit together.
- [API guide](docs/api-guide.md) — entity, resource, and serialization conventions.
- [Branching and releases](docs/branching-and-releases.md) — branch naming, conventional commits, release tags.
- [Deployment](docs/deployment.md) — Docker, Helm, and parallel-worktree setup.
- [Contributing](.github/CONTRIBUTING.md), [Code of Conduct](CODE_OF_CONDUCT.md), [Security](.github/SECURITY.md).

## License

[MIT](LICENSE).
