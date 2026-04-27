# Aura - API Platform Project

## Overview

Aura is built on **API Platform**, a Symfony-based framework for building API-first applications. It uses a monorepo structure with three main components: a PHP API backend, a Next.js PWA frontend, and Playwright E2E tests.

## Project Structure

```
Aura/
  api/                # Symfony/API Platform backend (PHP 8.4+)
  pwa/                # Next.js frontend (React/TypeScript)
  e2e/                # Playwright end-to-end tests
  helm/               # Kubernetes/Helm deployment charts
  docs/               # Project documentation
  .github/            # GitHub config (templates, workflows, policies)
  compose.yaml        # Docker Compose (dev environment)
  CHANGELOG.md        # Project changelog
  CODE_OF_CONDUCT.md  # Contributor code of conduct
  LICENSE             # MIT license
```

## Tech Stack

### API (Backend)
- **Framework**: Symfony 7.2 with API Platform 4.x
- **PHP**: >= 8.4
- **Database**: PostgreSQL 16 (via Doctrine ORM)
- **Server**: FrankenPHP
- **Real-time**: Mercure (WebSocket-like push)
- **Testing**: PHPUnit
- **File storage**: league/flysystem-bundle (local adapter in dev, swap to S3 later via `api/config/packages/flysystem.yaml`). User-uploaded media lives under `/app/var/media/` in the container, served by Caddy at `/media/*`.
- **Image processing**: intervention/image (GD driver). See `api/src/Service/ImageUploadService.php`.
- **Media model**: `MediaObject` entity is the shared upload target for any domain entity that needs files (today: `User.avatar`; later: task attachments, project covers). Uploads go through `POST /media-objects` (multipart), then entities link them via IRI.
- **Groups**: Reusable named user-sets (`UserGroup` entity exposed as `/groups` — API short name `Group`; the entity uses the `UserGroup`/`user_group` prefix because `group` is a reserved SQL keyword and `Group` clashes with `Symfony\Component\Serializer\Attribute\Groups`). Owner has full control (edit metadata, manage members, transfer ownership, delete); other members are read-only. Member-add by email lives at `POST /groups/{id}/members` (owner-only). `UserGroupAccessExtension` filters listings to groups the current user owns or belongs to; non-member item lookups return 404.
- **Group invites**: Adding a non-existent email to a group creates a `UserInvite` (one row per email, unique) plus a `GroupInvite` join row per group the address has been invited to. A signup-link email is sent and tokens are hashed (sha256) like `PasswordResetToken`. Public lookup at `GET /invites/{token}`; owner-only management at `GET /groups/{id}/invites` and `DELETE /groups/{id}/invites/{groupInviteId}`. When the invitee signs up via the link (`POST /users` with `inviteToken`), `UserPasswordHasherProcessor` joins them to every group attached to the invite — provided the signup email matches the invite email.
- **Key packages**: doctrine/orm, nelmio/cors-bundle, symfony/security-bundle, symfony/serializer

### PWA (Frontend)
- **Framework**: Next.js 15 (React)
- **Language**: TypeScript (path alias `@/*` resolves to the `pwa/` root)
- **Styling**: Tailwind CSS v4 + `tw-animate-css`
- **UI components**: shadcn/ui — Radix-based source components owned in `pwa/components/ui/` (button, input, label, card, alert, badge, checkbox, dropdown-menu, separator, textarea). `cn()` helper in `pwa/lib/utils.ts`. Design tokens (CSS variables under `:root` + `@theme inline`) live in `pwa/styles/globals.css`. The `pwa/components.json` config means `npx shadcn@latest add <component>` will drop new ones into `pwa/components/ui/` automatically.
- **State**: @tanstack/react-query
- **Forms**: Formik. shadcn's `Form` is react-hook-form-based, so we use a thin `FormikField` helper in `pwa/components/ui/formik-field.tsx` that wires `<Field as={Input}>` + `Label` + `ErrorMessage` together.
- **Admin**: @api-platform/admin (mounted at `/admin`) — off-limits for shadcn, it has its own UI system.
- **Rich text**: BlockNote (WYSIWYG markdown editor) + react-markdown / remark-gfm (read-only rendering). Shared editor lives in `pwa/components/editor/`.
- **Avatars**: Reusable `UserAvatar` in `pwa/components/user/`. Renders the uploaded image when present, otherwise white initials on the user's `personalizedColor` (contrast-safe palette picked at registration).
- **Icons**: lucide-react.
- **Package manager**: pnpm

### E2E Tests
- **Framework**: Playwright

### Infrastructure
- Docker Compose for local development
- Helm charts + Skaffold for Kubernetes deployment

## Development

### Prerequisites
- Docker & Docker Compose
- PHP 8.4+ (for local API development)
- Node.js + pnpm (for local PWA development)

### Running Locally
```bash
docker compose up -d
```
The API is served at `https://localhost` (FrankenPHP handles both API and PWA proxying).

### Parallel Worktree Stacks
To run multiple worktrees concurrently without port/container collisions, generate a per-worktree `.env` first:
```bash
scripts/worktree-env.sh           # writes ./.env with a unique project name and port block
docker compose up -d
```
Linked worktrees get ports in the 20000+ range; the main checkout keeps default ports. See `docs/deployment.md` for details.

### API Development
```bash
cd api
composer install
bin/console doctrine:migrations:migrate
bin/phpunit                           # Run tests
```

### PWA Development
```bash
cd pwa
pnpm install
pnpm dev                              # Next.js dev server on port 3000
pnpm lint                             # ESLint
```

### E2E Tests
```bash
cd e2e
npm install
npx playwright test
```

## Code Conventions

### PHP / API
- Follow [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html)
- Entities live in `api/src/Entity/` with API Platform attributes
- Use Doctrine ORM attributes for mapping
- Validate with Symfony Validator constraints
- Tests in `api/tests/Api/`

### TypeScript / PWA
- Components in `pwa/components/`; shadcn primitives live in `pwa/components/ui/` and should be the default for buttons, inputs, labels, alerts, cards, dropdowns, etc. Reach for raw Tailwind only when no primitive fits.
- Pages follow Next.js file-based routing in `pwa/pages/`.
- Imports prefer the `@/*` alias (`@/components/...`, `@/contexts/...`, `@/lib/...`) over deep relative paths.
- Use Tailwind CSS for styling. Reference design tokens via the shadcn variables (`bg-background`, `text-muted-foreground`, `border-input`, `text-destructive`, etc.) where possible; the brand `cyan-700` is wired up as `--primary`.
- Forms backed by Formik should use `FormikField` from `pwa/components/ui/formik-field.tsx` rather than hand-rolled `<Field>` + `<label>` + `<ErrorMessage>` blocks.
- Long-form description fields use `MarkdownEditor` for input and `MarkdownView` for rendering (both from `pwa/components/editor/`). Content is stored as markdown in the API's `TEXT` columns.

### E2E Tests
- Shared helpers (auth + markdown editor) live in `e2e/tests/helpers.js` — prefer them over duplicating `registerAndSignIn` per spec.

### Git & Branching
- `main` is the only long-lived branch — always deployable
- Create short-lived branches: `feature/`, `fix/`, `chore/`, `docs/`, `refactor/`
- Squash and merge PRs
- See `docs/branching-and-releases.md` for the full strategy

### Releases
- Date-based build numbers: `YYYY.MM.DD.N` (e.g., `2026.04.12.1`)
- Tag `main` when ready to release: `git tag 2026.04.12.1 && git push origin 2026.04.12.1`

## Key Configuration
- **Database URL**: `DATABASE_URL` env var (default: PostgreSQL on `database:5432`)
- **Mercure**: JWT secret via `CADDY_MERCURE_JWT_SECRET`
- **CORS**: Configured via nelmio/cors-bundle
- **Trusted proxies/hosts**: Configurable via env vars

## Branch Protection

`main` is protected:
- All CI status checks must pass (Tests, Docker Lint)
- At least 1 approving review required
- PRs are auto-assigned to their author

## Documentation

### Project Docs (`docs/`)
- `docs/architecture.md` - System architecture and component relationships
- `docs/api-guide.md` - API development patterns and conventions
- `docs/branching-and-releases.md` - Branch naming, PR workflow, conventional commits, and release process
- `docs/deployment.md` - Deployment and infrastructure guide

### GitHub Community Files (`.github/`)
- `.github/CONTRIBUTING.md` - Contribution guidelines
- `.github/SECURITY.md` - Security vulnerability reporting policy
- `.github/SUPPORT.md` - How to get help
- `.github/PULL_REQUEST_TEMPLATE.md` - PR template
- `.github/ISSUE_TEMPLATE/bug_report.yml` - Bug report form
- `.github/ISSUE_TEMPLATE/feature_request.yml` - Feature request form

### Root Files
- `CHANGELOG.md` - Project changelog (Keep a Changelog format)
- `CODE_OF_CONDUCT.md` - Contributor Covenant code of conduct
- `CONTRIBUTORS.md` - Project contributors list
- `LICENSE` - MIT license
