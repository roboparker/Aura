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
- **Media model**: `MediaObject` entity is the shared upload target for any domain entity that needs files. Used by `User.avatar`, `Task.attachments`, and `Project.attachments`. Uploads go through `POST /media-objects` (multipart), then entities link them via IRI. Sensitive bytes (kind=attachment) stream through the gated `GET /media-objects/{id}/download` endpoint, which re-checks owner / task-readability / project-membership before serving. Avatar bytes stay on the public `/media/...` Caddy route.
- **Spaces (#181, in progress)**: Top-level container for content. Every user gets a non-deletable personal "Private" space at signup; additional shared spaces can be created and shared with users / `UserGroup`s. Roles are `admin` and `member` — admins manage members, edit metadata, and delete the space; members can read/write content and edit/delete only their own. `Space`, `SpaceMembership` (direct user, unique on space+user), and `SpaceGroupMembership` (transitive via group, unique on space+group) live under `api/src/Entity/`. **PR 1 (current)** is additive only: it provisions the `Space` entity, `/spaces` CRUD (admin-gated writes via `SpaceCreateProcessor` + entity-level `isAdmin(user)` security expressions), `SpaceAccessExtension` (EXISTS-subquery scoping mirroring `ProjectAccessExtension`, with both direct and group-inherited membership), and a backfill in `Version20260507000000` that creates one personal space per existing user and links every existing project / discussion / custom-field-definition to it. Discussion + CustomFieldDefinition denormalize their parent project's space via `PrePersist` (`syncSpaceFromProject`). `ProjectSpaceDefaultListener` is a Doctrine entity listener that auto-fills `Project.space` to the owner's personal space (provisioning one if missing) on direct-EM persistence — production code goes through `ProjectOwnerProcessor`, but the listener keeps tests / fixtures / future CLI commands working without forcing every call site to know about spaces. Owner-based access on Project / Discussion / CFD is **unchanged in PR 1** — PR 2 ([#185](https://github.com/roboparker/Aura/issues/185)) is what swaps the access predicates over to space membership. Personal-space invariant enforced at the DB level by partial unique index `uniq_space_personal_per_user` on `space (created_by_id) WHERE is_personal = true`. The personal "Private" space is created in `UserPasswordHasherProcessor` post-persist so the membership row and space row land in the same flush as the user — a half-failed signup can't leave an orphan space behind. Follow-up PRs: PR 2 access predicates ([#185](https://github.com/roboparker/Aura/issues/185)), PR 3 space invites ([#186](https://github.com/roboparker/Aura/issues/186)), PR 4 PWA ([#187](https://github.com/roboparker/Aura/issues/187)).
- **Groups**: Reusable named user-sets (`UserGroup` entity exposed as `/groups` — API short name `Group`; the entity uses the `UserGroup`/`user_group` prefix because `group` is a reserved SQL keyword and `Group` clashes with `Symfony\Component\Serializer\Attribute\Groups`). Owner has full control (edit metadata, manage members, transfer ownership, delete); other members are read-only. Member-add by email lives at `POST /groups/{id}/members` (owner-only). `UserGroupAccessExtension` filters listings to groups the current user owns or belongs to; non-member item lookups return 404.
- **Group invites**: Adding a non-existent email to a group creates a `UserInvite` (one row per email, unique) plus a `GroupInvite` join row per group the address has been invited to. A signup-link email is sent and tokens are hashed (sha256) like `PasswordResetToken`. Public lookup at `GET /invites/{token}`; owner-only management at `GET /groups/{id}/invites` and `DELETE /groups/{id}/invites/{groupInviteId}`. When the invitee signs up via the link (`POST /users` with `inviteToken`), `UserPasswordHasherProcessor` joins them to every group attached to the invite — provided the signup email matches the invite email.
- **Custom fields**: Per-project schema (`CustomFieldDefinition`) with five types — text, number, date, dropdown, checkbox. Definitions are owner-managed (CRUD at `/custom_field_definitions`, owner-only writes); reads are scoped to project members by `CustomFieldDefinitionAccessExtension`. The PWA mounts `CustomFieldsManager` (`pwa/components/custom-fields/`) on a dedicated page at `/projects/{id}/custom-fields` (linked from the project detail page next to Discussions): list + composer with conditional dropdown-options editor, owner-only edit/delete, members see read-only. Per-task values live on `CustomFieldValue`, embedded as `Task.customFieldValues` (`{definition, value}` pairs) — clients write the whole array via the Task `task:write` group and orphanRemoval reaps anything dropped. The `(task_id, definition_id)` unique constraint is `DEFERRABLE INITIALLY DEFERRED` so PATCH's delete-then-insert collapses cleanly. `ValidCustomFieldValues` (class-level constraint on Task) enforces: definition belongs to the task's project, no duplicate definition refs, scalar matches the field's type (text=string, number=int|float, date=ISO `Y-m-d`, dropdown ∈ options, checkbox=bool), and every `required: true` definition has a non-empty value at save time.
- **Search**: Postgres full-text search via STORED `tsvector` generated columns on `task` (title weight A + description weight B), `comment.body`, `custom_field_value` (extracted from the JSON value via `value #>> '{}'`), `project` (title A + description B), and `discussion` (title A + body B), all GIN-indexed. Per-resource filters expose `?search=q` on `GET /tasks` (`App\Filter\TaskSearchFilter`), `GET /projects` (`App\Filter\ProjectSearchFilter`), and `GET /discussions` (`App\Filter\DiscussionSearchFilter`); all three use `websearch_to_tsquery` (accepts user-typed phrases / `-exclusions` / quotes without parsing) and replace the default order with `ts_rank` desc when a query is present. Task search additionally OR-joins comment bodies and custom field values (text/dropdown/number/date strings). Companion task-only filters: `App\Filter\TaskStatusFilter` (`?status=open|completed`), `DateFilter` on `dueDate`, `SearchFilter` exact on `tags`/`project`/`assignees`, and `OrderFilter` on `createdOn`/`dueDate`/`title`/`completedOn`. Custom DQL functions `SEARCH_VECTOR_MATCH` and `SEARCH_VECTOR_RANK` live in `App\Doctrine\Functions\` and are registered in `doctrine.yaml`. The PWA `/search` page surfaces all three resources behind a Tasks / Projects / Discussions tab toggle (`?kind=`), with the task-specific filter chips only shown on the Tasks tab.
- **Discussions (#91)**: Project-level threads (`Discussion` entity at `/discussions`). Members of a project can create / list / read; only the author can edit; the author OR project owner can delete or pin/lock. `DiscussionAccessExtension` scopes listings + item lookups to projects the current user belongs to (404 for outsiders). Author is stamped server-side by `DiscussionAuthorProcessor`. Categories: `general`, `ideas`, `announcements`, `q-and-a`. Default order is `isPinned` DESC, then `createdAt` DESC, so pinned discussions float to the top of any list. The PWA renders `DiscussionsPanel` (`pwa/components/discussions/`) on a dedicated list page at `/projects/{id}/discussions`, with each row's title linking to `/projects/{id}/discussions/{discussionId}` for the full body, edit form, and moderation controls. The project detail page exposes a "Discussions" link button instead of an embedded tab. There's also a top-level `/discussions` aggregator (linked from the navbar) that lists threads from every project the user belongs to with a project chip per row. `User` exposes id/email/name/avatar fields under the `discussion:read` group so author chips render without a follow-up fetch; `Project` likewise exposes id/title so the top-level aggregator renders project labels in-place.
- **MCP server (#92)**: Integrated [Model Context Protocol](https://modelcontextprotocol.io/) endpoint at `POST /mcp`. Streamable HTTP, JSON-RPC 2.0, Bearer-auth via `ApiToken` (sha256-hashed, `aura_pat_` prefix; one-shot plaintext returned only on `POST /api-tokens`). The `/mcp` firewall is stateless and declared before `main` so cookie/2FA listeners never see MCP traffic. Tools live under `api/src/Mcp/Tool/` and are auto-registered through the `app.mcp_tool` tag (`_instanceof` in `services.yaml`); each implements `App\Mcp\Tool\McpToolInterface`. Authorization mirrors the entity-level `security:` expressions via `App\Mcp\McpAuthorization`; serialization is plain-array (no JSON-LD envelope) via `App\Mcp\McpEntitySerializer`. Tool errors come back as `result.isError = true` so the model can recover; only protocol-level problems become JSON-RPC `error` envelopes. See `docs/mcp-server.md` and `api/config/mcp-server.json` for the example client config.
- **2FA (TOTP)**: opt-in per user, powered by [SchebTwoFactorBundle](https://symfony.com/bundles/SchebTwoFactorBundle/current/index.html) (`scheb/2fa-bundle` + `scheb/2fa-totp` + `scheb/2fa-backup-code`). The `User` entity implements `TwoFactorInterface` and `BackupCodeInterface`. The TOTP secret is stored encrypted (sodium secretbox via `App\Service\TwoFactorSecretCipher`, key derived from `APP_SECRET`) and decrypted into a transient cache by `App\EventListener\UserTotpCipherInjector` on Doctrine `postLoad`. Setup/verify/disable/regenerate-codes endpoints live at `/me/2fa/*` (controller: `TwoFactorController`); the login challenge piggy-backs on the bundle's firewall listener at `/auth/2fa-check`, with JSON request/response handlers in `App\Security\TwoFactorJsonHandler`. Verify-code attempts are throttled per user via the `two_factor_verify` rate limiter (5/min). Recovery codes are stored as sha256 hashes (one-time use; consumed code removed from the JSON list).
- **Doctrine behaviours**: `stof/doctrine-extensions-bundle` (Gedmo) — used today for `Loggable` (audit history on `Task` and `Project`, table `ext_log_entries`, queried via `App\Controller\{Task,Project}ActivityController`). Prefer Gedmo behaviours (Timestampable, Sluggable, SoftDeleteable, Tree, Translatable, Blameable, …) over hand-rolled lifecycle hooks. The activity-log `username` column stores `/users/{uuid}` IRIs via `App\Service\IriActorProvider` so audit history is stable across email changes.
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
- Use Tailwind CSS for styling. Reference design tokens via the shadcn variables (`bg-background`, `text-muted-foreground`, `border-input`, `text-destructive`, etc.) where possible. shadcn tokens use the canonical neutral baseColor (no brand override yet); brand cyan still lives in raw utilities (`bg-cyan-700`, `text-cyan-700`) on the navbar and link elements.
- Forms backed by Formik should use `FormikField` from `pwa/components/ui/formik-field.tsx` rather than hand-rolled `<Field>` + `<label>` + `<ErrorMessage>` blocks.
- Long-form description fields use `MarkdownEditor` for input and `MarkdownView` for rendering (both from `pwa/components/editor/`). Content is stored as markdown in the API's `TEXT` columns.
- **Developer component docs**: every reusable component in `pwa/components/ui/` and the shared pieces under `pwa/components/{editor,user,common}/` has a documentation page at `pwa/pages/dev/components/<slug>.tsx`, indexed by `pwa/components/dev/registry.ts`. When you add a new reusable component, rename one, change a prop's public shape, or add a new variant, update the matching docs page (live example + source snippet via the `<CodeBlock />` helper) and the registry entry in the same PR. The index lives at `/dev/components`.

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
- The **Changelog workflow** (`.github/workflows/changelog.yml`) auto-regenerates `CHANGELOG.md` and creates the GitHub Release via [git-cliff](https://github.com/orhun/git-cliff) on tag push; rules live in `cliff.toml`. Only `feat:`/`fix:`/`security:`/`perf:`/`refactor:` commits make the changelog — keep PR titles in conventional-commit form so they land in the right section.

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
- `docs/mcp-server.md` - MCP server endpoint, token auth, tool catalog, client config

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
