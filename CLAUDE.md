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
- **Media model**: `MediaObject` entity is the shared upload target for any domain entity that needs files. Used by `User.avatar`, `Task.attachments`, and `Space.attachments`. Uploads go through `POST /media-objects` (multipart), then entities link them via IRI. Sensitive bytes (kind=attachment) stream through the gated `GET /media-objects/{id}/download` endpoint, which re-checks owner / task-readability / space-membership before serving. Avatar bytes stay on the public `/media/...` Caddy route. Project-level attachments were collapsed up to the space — shared files now live at `space_attachment`, so every project + member in a space sees one shared file pool managed in the space detail's Files tab.
- **Spaces (#181, #185)**: Top-level container for content. Every user gets a non-deletable personal "Private" space at signup; additional shared spaces can be created and shared with users / `UserGroup`s. Roles are `admin` and `member` — admins manage members, edit metadata, and delete the space; members can read/write content and edit/delete only their own. `Space`, `SpaceMembership` (direct user, unique on space+user), and `SpaceGroupMembership` (transitive via group, unique on space+group) live under `api/src/Entity/`. PR 1 ([#181](https://github.com/roboparker/Aura/issues/181)) provisioned the entities, `/spaces` CRUD (admin-gated writes via `SpaceCreateProcessor` + entity-level `isAdmin(user)` security expressions), `SpaceAccessExtension` (EXISTS-subquery scoping mirroring `ProjectAccessExtension`, with both direct and group-inherited membership), and the backfill that links every existing project / discussion / CFD to its creator's personal space. PR 2 ([#185](https://github.com/roboparker/Aura/issues/185)) is the behavior change: every access predicate, security expression, and project-membership check across the API now goes through space membership instead of the old `project.members` collection (which is dropped along with the `project_member` table; `Version20260507120000` backfills any pre-existing project members into `space_membership`). Helpers: `Space::hasMember(user)` / `isAdmin(user)` (UUID-based comparison so they survive `EntityManager::clear()`), `Space::getEffectiveUsers()` (deduped union of direct + group members), `Project::isAccessibleBy(user)` / `isSpaceAdmin(user)` / `getEffectiveMembers()`, `Task::isAccessibleBy(user)` (owner OR project's space includes user; standalone tasks remain owner-only). For DQL, `App\Doctrine\SpaceMembershipDql::userBelongsToProjectSpace($projectAlias)` returns the direct-or-via-group EXISTS fragment so the same boilerplate isn't copy-pasted across access extensions, the media-download controller, the task repository's reorder query, and the MCP tools. Access matrix: read/list any space member; create any space member (Project's `Post` accepts `space === null` so the existing PWA — which doesn't know about spaces yet — keeps working, the processor defaults it to the caller's personal space); edit any space member; delete = creator OR space admin (Project) / author OR space admin (Discussion); pin/lock Discussion + CRUD on CustomFieldDefinition = space admin only. Discussion is parented directly by `Space` (no project link). `CustomFieldDefinition` denormalises its parent project's space via `PrePersist` (`syncSpaceFromProject`); `ProjectSpaceDefaultListener` auto-fills `Project.space` for direct-EM persistence (tests, fixtures, future CLI). Personal-space invariant enforced at the DB by partial unique index `uniq_space_personal_per_user` on `space (created_by_id) WHERE is_personal = true`. The personal "Private" space is created in `UserPasswordHasherProcessor` post-persist so the membership row and space row land in the same flush as the user — a half-failed signup can't leave an orphan space behind. `POST /projects/{id}/members` is preserved as a thin shim that adds the resolved user to the project's space (role `member`) so the existing PWA keeps working until PR 4. Test helpers live in `App\Tests\Api\SpaceMembershipFixture` (`addProjectMember()` / `ensureSpaceMembership()`). PR 3 space invites ([#186](https://github.com/roboparker/Aura/issues/186)) is described under "Group + space invites" above. PR 4 ([#187](https://github.com/roboparker/Aura/issues/187)) shipped the active-space PWA UX: `pwa/contexts/ActiveSpaceContext.tsx` loads the user's space list from `GET /spaces`, exposes `{spaces, personalSpace, activeSpace, setActiveSpace, refresh, isActiveSpaceAdmin}`, and persists the choice in localStorage under `aura.activeSpaceId` (falls back to the personal space when the persisted choice is no longer accessible). `pwa/components/common/SpaceSwitcher.tsx` adds the dropdown next to the Aura wordmark with a Lock icon for personal spaces. `/spaces` (index) and `/spaces/{id}` (detail with members, invite-by-email form, pending invites list with revoke, and admin-only Delete) are the management surface; the navbar's "Manage spaces" link drops you into them. `/projects` and `/discussions` listings append `?space={iri}` to scope to the active space; new projects POSTed from `/projects` pin themselves to the active space's IRI so a user in a shared space doesn't accidentally drop new work into their personal one. Owner-gating in `DiscussionsPanel` and `CustomFieldsManager` is replaced with `isSpaceAdmin` derived by looking up the project's parent space in the already-loaded space list (no extra fetch). Project detail page drops the per-project add-member form (members are space-level now) and surfaces a "Space" badge plus "Manage members in space" link. The `?space=` SearchFilter on Project + Discussion lives behind the `space:read` group on the User entity (so the User row embedded inside `SpaceMembership.user` serializes with email/name/avatar fields the PWA needs).
- **Groups**: Reusable named user-sets (`UserGroup` entity exposed as `/groups` — API short name `Group`; the entity uses the `UserGroup`/`user_group` prefix because `group` is a reserved SQL keyword and `Group` clashes with `Symfony\Component\Serializer\Attribute\Groups`). Owner has full control (edit metadata, manage members, transfer ownership, delete); other members are read-only. Member-add by email lives at `POST /groups/{id}/members` (owner-only). `UserGroupAccessExtension` filters listings to groups the current user owns or belongs to; non-member item lookups return 404.
- **Group + space invites**: One `UserInvite` per email (unique) collects every pending attachment for that address: `GroupInvite` rows for groups, `SpaceInvite` rows for spaces (#186). Tokens are sha256-hashed like `PasswordResetToken`; the plaintext is only ever in the email. Add-by-email: `POST /groups/{id}/members` (owner-only) and `POST /spaces/{id}/members` (admin-only) — each branches on whether the email matches an existing user (direct membership) vs unknown (upsert + invite). `App\Service\InviteMailer` renders the signup-link email with the union of every group + space currently attached, qualified as `group "X"` / `space "Y"` so a recipient invited to both gets one coherent message; token rotation on every send keeps the most recent email authoritative. Public lookup at `GET /invites/{token}` returns `email`, `groups[]`, `spaces[]`, and `expiresAt`. Admin/owner management lives at `GET|DELETE /groups/{id}/invites[/...]` and `GET|DELETE /spaces/{id}/invites[/...]`; revoking the last attachment on a `UserInvite` removes the parent so a token can't redeem to nothing. When the invitee signs up via the link (`POST /users` with `inviteToken`), `UserPasswordHasherProcessor` walks both `groupInvites` and `spaceInvites` and attaches the new user — provided the signup email matches the invite email.
- **Custom fields (#227)**: Per-project schema (`CustomFieldDefinition`). Storage shape is `(kind, subtype, config, footer, nullable)` — `kind` is the top-level family (`boolean`/`text`/`numeric`/`date`/`select`/`reference`), `subtype` the specialisation (e.g. `numeric.money`, `select.multi`, `reference.user`), `config` the per-kind JSON payload (options, min/max, currency, multi, …), `footer` an optional `{kind, label?}` aggregation descriptor, `nullable` the inverse of the legacy `required` flag (kept as a denormalised mirror column for now). Per-kind editor + validator logic lives in `api/src/CustomField/Type/` behind `CustomFieldTypeInterface`: each (kind, subtype) is one strategy class, auto-tagged `app.custom_field_type` and resolved through `CustomFieldTypeRegistry`. Adding a new kind is a one-class drop on both sides of the wire (PHP strategy + matching TS entry in `pwa/components/custom-fields/kind-editors.tsx`). Definitions are space-admin managed (CRUD at `/custom_field_definitions`); reads scoped via `CustomFieldDefinitionAccessExtension`. The PWA mounts `CustomFieldsManager` (`pwa/components/custom-fields/`) on `/projects/{id}/custom-fields`: kind/subtype dropdowns swap in the matching config editor (currency for money, min/max for numeric/date, structured options list for select, length + regex for text). Per-task values live on `CustomFieldValue`, embedded as `Task.customFieldValues` (`{definition, value}` pairs) — clients write the whole array via the Task `task:write` group and orphanRemoval reaps anything dropped. The `(task_id, definition_id)` unique constraint is `DEFERRABLE INITIALLY DEFERRED` so PATCH's delete-then-insert collapses cleanly. `ValidCustomFieldValues` (class-level constraint on Task) dispatches through the strategy registry — top-level invariants (project-scope, no dup definition, required-when-not-nullable) stay in the validator; per-value shape rules live on each strategy. Companion `ValidCustomFieldDefinition` (class-level on CFD) dispatches `validateConfig()` + checks `footer.kind` against the strategy's supported aggregations. Money values are `{amount: int-minor-units, currency: ISO-4217}` with the currency pinned on the field's `config`; reference values are IRIs scoped to the field's space (`reference.user` walks `space.getEffectiveUsers()`; the rest compare `target.space === field.space`). The FTS projection on `custom_field_value.value_search` is written by `CustomFieldValueSearchSubscriber` calling `strategy.searchText()` on persist/update so references serialize their target's display label and money emits `<amount> <currency>` — `search_vector` then derives from `value_search` via STORED generated column. Legacy `CustomFieldDefinition::setType()` / `setOptions()` remain as internal entity helpers (no `#[Groups]`, off the wire) so test seeders and the migration backfill still speak the old labels; the canonical API contract is `{kind, subtype, config, nullable}`.
- **Custom-field footer aggregation (#227)**: `GET /projects/{id}/custom_field_footers` returns one row per CFD that has a `footer.kind` configured, with the aggregation computed over the filtered task list. Endpoint honours `?search=`, `?status=`, `?overdue=`, `?dueDate[before|after|strictly_*]` (Task collection filter subset). Numeric int/float/money push through SQL (`(value #>> '{...}')::numeric` + `SUM/AVG/MIN/MAX`), date kinds use lex MIN/MAX on the JSON-extracted text, every kind supports `count` of non-null values. Money returns `{amount, currency}` so the PWA's `CustomFieldFooterRow` (under the project task list) can format with `Intl.NumberFormat`. Multi-value sum/avg/min/max returns null in v1 — JSON array unroll is out of scope.
- **Comments (#228)**: Single `Comment` entity at `/comments` covers both tasks and pages via a polymorphic parent — `commentable_type` discriminates between `'task'` and `'page'`, and exactly one of `task` / `page` FKs is set (enforced by `chk_comment_parent_exactly_one`). Threads are flat chronological — no reply tree. `@mention` tokens anywhere in `body` fire one `mention` Notification per resolved recipient through `CommentMentionService`, with the recipient set parent-aware: task comments resolve through `task.owner + project.space`; page comments through `page.space`. Mercure live updates on both parents via `CommentMercurePublisher` (topic `<parentIri>/comments`); the subscription cookie endpoint covers `/tasks/{id}/comments/mercure-token` and `/pages/{id}/comments/mercure-token`. Access scoping mirrors the parent: `CommentAccessExtension` LEFT-joins through `task` / `page` and OR's the per-parent visibility check (task: owner or project-space membership; page: space membership). Delete-escalation also branches: task owner can delete on tasks, space admin can delete on pages; author can always delete their own. The PWA renders both surfaces through one shared `CommentsPanel` (`pwa/components/common/CommentsPanel.tsx`) and one live-update hook (`useCommentLiveUpdates`).
- **Search**: Postgres full-text search via STORED `tsvector` generated columns on `task` (title weight A + description weight B), `comment.body`, `custom_field_value` (extracted from the JSON value via `value #>> '{}'`), `project` (title A + description B), and `discussion` (title A + body B), all GIN-indexed. Per-resource filters expose `?search=q` on `GET /tasks` (`App\Filter\TaskSearchFilter`), `GET /projects` (`App\Filter\ProjectSearchFilter`), and `GET /discussions` (`App\Filter\DiscussionSearchFilter`); all three use `websearch_to_tsquery` (accepts user-typed phrases / `-exclusions` / quotes without parsing) and replace the default order with `ts_rank` desc when a query is present. Task search additionally OR-joins comment bodies and custom field values (text/dropdown/number/date strings). Companion task-only filters: `App\Filter\TaskStatusFilter` (`?status=open|completed`), `DateFilter` on `dueDate`, `SearchFilter` exact on `tags`/`project`/`assignees`, and `OrderFilter` on `createdOn`/`dueDate`/`title`/`completedOn`. Custom DQL functions `SEARCH_VECTOR_MATCH` and `SEARCH_VECTOR_RANK` live in `App\Doctrine\Functions\` and are registered in `doctrine.yaml`. The PWA `/search` page surfaces all three resources behind a Tasks / Projects / Discussions tab toggle (`?kind=`), with the task-specific filter chips only shown on the Tasks tab.
- **Copy discussion (#182)**: `POST /discussions/{id}/copy` with optional body `{ "space": "/spaces/{uuid}" }` (defaults to source's space). Clones title (with idempotent ` (copy)` suffix) + body + category. Clone's `author` is the current user. `isPinned` and `isLocked` reset to false on the clone (moderation state stays with the source). Comments are NOT copied — the clone starts as a fresh thread. Auth: caller must be a member of both the source space and the target space. PWA Copy button lives next to the Move picker on the discussion detail.
- **Copy page (#182)**: `POST /pages/{id}/copy` with optional body `{ "space": "/spaces/{uuid}" }` (defaults to source's space). Clones title + body; clone's `createdBy` is the current user. Title gets an idempotent ` (copy)` suffix. The clone root is always top-level in the target (parent FK cleared) even when copying within the same space, so the result reads as "a new doc" rather than a sibling of the source. **Descendants: opt-in via `{ includeDescendants: true }` on the body** — a breadth-first walk clones every descendant page with a parent that points at the clone of its source parent, so the hierarchy mirrors verbatim inside the clone. Only the root carries the `(copy)` suffix; descendants keep their original titles to avoid noise. Comments are NOT copied (cloned tree starts as fresh thread surfaces). Auth: caller must be member of both source's space and target space. PWA "Copy" button + "include sub-pages" checkbox on the page detail (`pwa/pages/pages/[pageId].tsx`).
- **Copy project (#182)**: `POST /projects/{id}/copy` with optional body `{ "space": "/spaces/{uuid}" }` (defaults to the source's space). Deep-clones the project metadata + every `CustomFieldDefinition` attached to it (name, type, options, position, required). Title gets a " (copy)" suffix, idempotent so repeated copies don't pile up "(copy) (copy)". Clone's `createdBy` is the current user — fresh audit history. Discussions live at the space level and are never carried (use `POST /discussions/{id}/copy` to clone individual threads). Comments are NOT copied. **Tasks: opt-in via `{ includeTasks: true }` on the body** — when set, each task is cloned with title + description + dueDate + recurrenceRule + position + tags (only tags the caller owns). Owner becomes the caller; assignees, reminders, completedOn, and custom field values are deliberately dropped. Auth: caller must be a member of both the source's space AND the target space. PWA "Copy" button + "include tasks" checkbox live on the project detail (`pwa/pages/projects/[id].tsx`) next to the "Move to" picker.
- **Move discussion between spaces (#182)**: `POST /discussions/{id}/move` with body `{ "space": "/spaces/{uuid}" }` (bare UUIDs accepted too). Auth bar: caller must be a member of both the source space and the target space; non-membership on either side returns 404 to match the access-extension existence-hiding shape. Audit history preserved via Gedmo `Loggable`. PWA picker lives on the discussion detail (`pwa/pages/discussions/[id].tsx`), gated on author-or-space-admin.
- **Move page between spaces (#182)**: `POST /pages/{id}/move` with body `{ "space": "/spaces/{uuid}" }` (bare UUIDs accepted too). Same source-AND-target membership bar as the project move. Recursively re-stamps `space` on the moved page and every descendant via a breadth-first walk; clears the moved page's `parent` FK so a page never points at a parent in a different space (children stay attached to their immediate parent and follow the move). Audit history preserved via Gedmo `Loggable`. PWA picker lives on the page detail (`pwa/pages/pages/[pageId].tsx`), gated on author-or-space-admin and only visible when the user belongs to more than one space.
- **Move project between spaces (#182)**: `POST /projects/{id}/move` with body `{ "space": "/spaces/{uuid}" }` (bare UUIDs accepted too). Caller must be a member of both the source and the target space — non-membership on either side returns 404 to mirror the access-extension existence-hiding shape. Cascades the new space onto child rows that cache it: `CustomFieldDefinition.space` is re-stamped explicitly because its `syncSpaceFromProject` PrePersist hook only fills the cache on insert. Tasks need no special handling — their access flows through `task.project.space`. Discussions are unaffected — they live on the space directly, not the project. Audit history is preserved automatically via Gedmo `Loggable`. PWA exposes a "Move to…" picker on the project detail page (`pwa/pages/projects/[id].tsx`) listing every space the user belongs to. The picker reuses `ActiveSpaceContext.spaces` so no extra fetch is needed.
- **Pages (#183)**: Long-form Notion-style documents owned by a `Space`. `Page` (title + markdown body, tree via self-FK `parent`, `position`, `createdBy`) at `/pages`. Comments live in the unified `Comment` entity (#228) — see the **Comments** entry above. Access mirrors the space-membership matrix: any space member reads/lists/creates; the page author or a space admin can edit/delete (cascade-on-delete reaps descendants and comments). `PageAuthorProcessor` stamps the author server-side; `PageAccessExtension` scopes queries via the same `(direct OR via group)` EXISTS pattern as the rest of the access extensions. Postgres FTS via `App\Filter\PageSearchFilter` exposes `?search=q` on `GET /pages` (STORED `search_vector` = title weight A + body B, GIN-indexed, `websearch_to_tsquery`, ranked by `ts_rank` when present). Audit history at `GET /pages/{id}/activity` mirrors `TaskActivityController`. The PWA renders `/pages` as a top-level aggregator (linked from the navbar, scoped to the active space, with search input + new-page composer) and `/pages/{id}` for the detail view (markdown editor, sub-page list, flat comment thread via the shared `CommentsPanel`, author/admin-only edit/delete). `User` and `Space` expose id/name/avatar fields under `page:read` so author chips and the space label render without follow-up fetches.
- **Discussions (#91)**: Space-level threads (`Discussion` entity at `/discussions`). Originally project-scoped; reparented to the space so the natural unit of conversation matches the access boundary. Any space member can create / list / read; only the author can edit; the author OR space admin can delete or pin/lock. `DiscussionAccessExtension` scopes listings + item lookups to spaces the current user belongs to (direct or via group; 404 for outsiders). Author is stamped server-side by `DiscussionAuthorProcessor`. Categories: `general`, `ideas`, `announcements`, `q-and-a`. Default order is `isPinned` DESC, then `createdAt` DESC. The PWA renders `DiscussionsPanel` (`pwa/components/discussions/`, takes `spaceIri` + `currentUserIri` + `isSpaceAdmin`) on the top-level `/discussions` page (scoped to the active space) and inside the space detail's Discussions tab. Each row links to `/discussions/{id}` for the full body, edit form, move/copy picker (targets any space the caller belongs to), and moderation controls. `User` exposes id/email/name/avatar fields under the `discussion:read` group so author chips render without a follow-up fetch.
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

### Docker Desktop port flaps (Windows)
Docker Desktop occasionally stops forwarding host → WSL2 ports after sleep/hibernate; containers stay healthy but `https://localhost` hangs from the host. Run `scripts/watch-port-forward.sh` in a side terminal — it polls the host URL and restarts the `php` container when the forward dies, which rebuilds the bridge. Tunables via env vars (`WATCH_URL`, `WATCH_SERVICE`, `POLL_INTERVAL`, `FAIL_THRESHOLD`, `PROBE_TIMEOUT`, `COOLDOWN`); see the script header.

### API Development
```bash
cd api
composer install
bin/console doctrine:migrations:migrate
bin/phpunit                           # Run tests
```

### Static analysis (PHPStan)
PHPStan runs against `api/src` and `api/tests` at **level 10** (max strictness in PHPStan 2.x) as a required CI check. Config lives in [api/phpstan.dist.neon](api/phpstan.dist.neon); pre-existing violations are deferred via [api/phpstan-baseline.neon](api/phpstan-baseline.neon) so CI only fails on *new* errors. Regenerate after a cleanup pass with `docker compose run --rm phpstan analyse --generate-baseline=phpstan-baseline.neon`.

Extensions wired up:
- `phpstan/phpstan-symfony` — service-id / config-resolver awareness via `var/cache/test/App_KernelTestDebugContainer.xml`, console application loaded from [api/tests/phpstan/console-application.php](api/tests/phpstan/console-application.php).
- `phpstan/phpstan-doctrine` — `getRepository()` / `QueryBuilder` / DQL typing. Needs an `objectManagerLoader` because our entities use the `doctrine.uuid_generator` Symfony service; see [api/tests/phpstan/object-manager.php](api/tests/phpstan/object-manager.php). Bootstrapping the test kernel requires the test cache to be warm (`bin/console -e test cache:warmup`) — CI does this for you.
- `phpstan/phpstan-phpunit` — `TestCase` assertion awareness so test-side dataset / mock typing stops tripping false positives.

Locally:
```bash
docker compose exec php bin/console -e test cache:warmup                            # one-time per repo refresh
docker compose run --rm phpstan analyse --memory-limit=4G                           # full analysis
docker compose run --rm phpstan analyse src/Filter --memory-limit=4G                # subset
docker compose run --rm phpstan analyse --generate-baseline=phpstan-baseline.neon   # after a cleanup pass
```
When you fix a batch of baselined errors, regenerate `phpstan-baseline.neon` in the same PR so the cleared patterns can't silently regress.

### Coding style (PHP_CodeSniffer)
PHP_CodeSniffer enforces a PSR-12 ruleset over `api/src` and `api/tests` as a required CI check. Config: [api/phpcs.xml.dist](api/phpcs.xml.dist) (line-length sniff disabled — Doctrine columns + Symfony service attributes regularly overflow 120 chars). Migrations are excluded since they're autogenerated.

```bash
docker compose run --rm phpcs                       # lint
docker compose run --rm --entrypoint vendor/bin/phpcbf phpcs   # auto-fix what's auto-fixable
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
- All CI status checks must pass (Tests, PHPStan, PHPCS, Doctrine Schema, PWA Typecheck, E2E, Docker Lint)
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
