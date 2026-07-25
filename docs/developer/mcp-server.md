# MCP server

Madori ships an integrated [Model Context Protocol](https://modelcontextprotocol.io/) server so AI assistants like Claude Desktop and Claude Code can read and edit tasks, boards, and comments through the same authorization rules as the PWA.

The server is mounted at `POST /mcp` on the same FrankenPHP process that serves the API. There is no separate process to run.

## Transport

Streamable HTTP, JSON-RPC 2.0:

- `POST /mcp` — JSON-RPC envelope (single object or batch). Returns JSON for requests, HTTP 202 for notifications.
- The server returns plain `application/json` (not SSE) — Streamable HTTP clients accept either.
- `GET /mcp` returns 405; the server has no notifications to push.

Implemented methods: `initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`.

Protocol version: `2024-11-05`.

## Authentication

Send a personal access token in the `Authorization` header:

```
Authorization: Bearer madori_pat_<secret>
```

### Minting a token

Authenticate to the PWA, then `POST /api-tokens` with a name (and optional `accessPolicy` and `expiresAt`):

```bash
curl -X POST https://your-madori/api-tokens \
  -H 'Content-Type: application/ld+json' \
  -H 'Cookie: PHPSESSID=...' \
  -d '{"name": "Local CLI"}'
```

The response includes a one-shot `plainToken` field — copy it; subsequent `GET /api-tokens` calls do not return it again. The persisted database row stores only the sha256 hash.

A token acts **as its owner**. By default (`accessPolicy: null`) it can do exactly what the owner can. To narrow it, send an `accessPolicy` in the shared none/view/edit model (`App\Security\Access\AccessPolicy` — the same shape used for admin-impersonation consent):

```json
{
  "name": "Read-only support bot",
  "accessPolicy": {
    "categories": { "tasks": "view", "boards": "view" },
    "items": { "board": { "<uuid>": "edit" } }
  }
}
```

`categories` keys are `tasks` / `boards` / `pages` / `comments` / `notifications` / `files`; `items` keys are `board` / `page` / `task` mapping a UUID to a level (an item override wins over its category). Omitted categories default to `none`. The **same policy governs both the REST API and MCP**: REST requests are gated by `App\EventListener\AccessPolicyListener` (path→category, method→view/edit) + per-item collection filtering; MCP tool calls are gated by `App\Mcp\McpToolPolicy` (each tool → category + read/write). MCP enforcement is category-level (tool ids aren't parsed for per-item overrides).

Tokens authenticate via `Authorization: Bearer` on both the `/mcp` firewall and the main REST firewall (the authenticator only engages when the Bearer header is present and keeps the request stateless). `POST /api-tokens` and `GET /api-tokens` themselves use the cookie-based PWA session.

### Revoking

`DELETE /api-tokens/{id}` revokes the token. Item-level filtering means you cannot see or revoke another user's token IDs.

## Available tools

| Category    | Tool                                                                           |
| ----------- | ------------------------------------------------------------------------------ |
| Task        | `create_task`, `get_task`, `update_task`, `delete_task`, `list_tasks`, `search_tasks` |
| Relationships| `link_tasks`, `unlink_tasks`, `list_task_relationships` (subtasks / dependencies / links) |
| Board       | `create_board`, `get_board`, `update_board`, `delete_board`, `list_boards` |
| Space       | `list_spaces`                                                                   |
| Page        | `create_page`, `get_page`, `update_page`, `delete_page`, `list_pages`          |
| Assignment  | `assign_task`, `unassign_task`, `get_my_tasks`                                 |
| Comment     | `add_task_comment`, `list_task_comments`, `add_page_comment`, `list_page_comments` |
| Tag         | `list_tags`, `create_tag`                                                       |
| File        | `upload_file`, `list_files`, `download_file`                                   |
| Custom field| `get_custom_fields`                                                            |
| Time        | `list_projects`, `list_time_entries`, `log_time`, `start_timer`, `stop_timer`  |
| Expenses    | `list_expenses`, `log_expense`                                                 |
| Invoicing   | `list_clients`, `list_invoices`, `get_invoice`, `list_estimates`               |
| Analytics   | `get_analytics`                                                                 |
| Calendar    | `list_calendar_events`                                                         |
| Notifications| `list_notifications`, `mark_notifications_read`                               |

Call `tools/list` to inspect each tool's JSON Schema. Tools execute as the user that owns the bearer token; visibility, edit, and delete rules mirror the existing API Platform `security:` expressions on each entity.

> **"Project" means two things in this product.** `list_boards` returns task
> boards; `list_projects` returns *client* projects — the billing unit that time
> is tracked against and invoiced from. They're unrelated, and the tool
> descriptions say so, because a model that conflates them will log time against
> the wrong thing.

Create tools that target a space (`create_board`, `create_page`) accept an optional `spaceId` — call `list_spaces` to discover the ids, or omit it to default to the caller's personal space.

> **Custom field values** — `get_custom_fields` returns a board's defined fields (`CustomFieldDefinition`). Per-task values (`CustomFieldValue`, #227) are written through `update_task`'s `customFieldValues` array (`[{definitionId, value}]`), which replaces the task's whole value set and is validated by `ValidCustomFieldValues` just like the REST path.

### Time and invoicing

The billing tools ride two space-role categories with deliberately different
defaults, inherited from `SpacePermissionResolver` rather than restated:

- **`time_entries`** is on for a plain space member — everyone tracks their own
  time. `list_time_entries` defaults to the caller's own entries; pass
  `mine: false` for the whole space.
- **`invoices`** is in `SpacePermission::ADMIN_RESERVED`, so a plain member sees
  **nothing** until a space admin grants a role. `McpBillingAuthorization` routes
  reserved categories through `canByExplicitGrant()` exactly like
  `SpacePermissionVoter` does — MCP must never be more permissive than REST.

Rates are never accepted from the caller. `log_time` and `start_timer` take a
`categoryId`, and the entity derives the rate, currency and billability from it
on save, so an agent can't invent a billing rate. The write rules that matter —
tracker stamping, the one-running-timer invariant, the billed-entry freeze, and
the submitted-week lock — live in `App\Service\TimeEntryGuard`, shared with the
REST processor so the two surfaces can't drift.

Invoice *creation* is deliberately absent: generating one is a period-selection
and entry-selection flow with real financial consequences, and it stays in the
web UI for now. The tools cover reading invoices and estimates plus the daily
time- and expense-tracking loop.

**Expenses ride `time_entries`, not `invoices`** — recording a cost is a
tracking concern like logging time (everyone records their own), distinct from
seeing what the business bills. `list_estimates`, by contrast, rides `invoices`.
This mirrors the REST `security:` expressions exactly.

### Analytics

`get_analytics` returns the business metrics as time series — see
[business-analytics.md](business-analytics.md) for the metric definitions. It's
the one tool that **spans two permission categories**, and that makes its gating
different from every other tool here.

`McpToolPolicy` maps each tool to a single category, so `get_analytics` is
mapped at the stricter of the two (`invoices`). That alone would be wrong in the
other direction, though: a token narrowed to `invoices` would then receive its
owner's *time* metrics too, quietly widening the scope its owner chose. So the
tool filters again per metric, checking both gates:

1. the caller's **space role** for the metric's category (money metrics need an
   explicit grant, being `ADMIN_RESERVED`), and
2. the calling **token's own `AccessPolicy`** for that same category, via
   `ActorPolicyResolver`.

Metrics failing either gate are omitted from the response rather than raising an
error, so a partially-scoped token gets a shorter answer instead of a failure.

Values carry no unit in the numbers themselves, so the response states them
explicitly: money is **minor currency units** (cents), time is **seconds**. A
model that assumes dollars reports a 100× error.

`spaceId` is optional only when the caller can read exactly one space. With
several, the tool refuses and asks for one rather than guessing — picking
silently would produce a confidently wrong answer the model can't detect.

### Calendar and notifications

`list_calendar_events` projects a space's due-dated tasks onto a date window,
**expanding recurring series into one entry per occurrence** — a weekly task
appears on each of its dates, not once on its anchor. Both this tool and
`GET /calendar` share `App\Service\CalendarOccurrenceResolver` so the recurrence
math can't diverge; each owns only its serialization. The window is capped at
`CalendarOccurrenceResolver::MAX_RANGE_DAYS` (62) so an unbounded range can't
materialise an unbounded number of occurrences.

`list_notifications` / `mark_notifications_read` operate on the caller's own
inbox — a notification has exactly one recipient, so scoping is a recipient
filter with no space permission involved. `mark_notifications_read` requires
explicit `notificationIds` **or** `all: true`; there is no implicit "clear
everything", because an inbox the user hasn't read is precisely what an agent
shouldn't wipe by omission.

## Errors

The dispatcher distinguishes JSON-RPC errors (malformed envelopes, unknown methods → `error.code` per the spec) from tool errors (invalid input, 404/403/422 → success envelope with `result.isError = true`). Tool errors include a human-readable message in `result.content[0].text` so the model can recover and retry.

## Client config example

### Claude Desktop / Claude Code (`mcpServers`)

```json
{
  "mcpServers": {
    "madori": {
      "url": "https://your-madori-instance.com/mcp",
      "headers": {
        "Authorization": "Bearer madori_pat_PASTE_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

For local dev (default `compose.yaml` ports):

```json
{
  "mcpServers": {
    "madori": {
      "url": "https://localhost/mcp",
      "headers": { "Authorization": "Bearer madori_pat_PASTE_YOUR_TOKEN_HERE" }
    }
  }
}
```

A copy-paste-ready file lives at [`api/config/mcp-server.json`](../../api/config/mcp-server.json).

## Implementation notes

- Token storage mirrors `PasswordResetToken` and `UserInvite`: 32 random bytes, prefixed `madori_pat_`, hashed with sha256 on persist. The cleartext is shown once and never again.
- The `/mcp` firewall is stateless and declared before `main`, so `json_login` and the SchebTwoFactorBundle listener never see `/mcp` traffic.
- Tools live under `api/src/Mcp/Tool/` and are auto-registered via the `app.mcp_tool` tag (`_instanceof` in `services.yaml`). To add a new tool, implement `App\Mcp\Tool\McpToolInterface` — that's the entire wiring.
- All tools share `App\Mcp\McpAuthorization` (rules duplicated from the entity `security:` expressions), `App\Mcp\McpEntitySerializer` (plain-array shape, no JSON-LD envelope), and `App\Mcp\McpInputHelper` (UUID/date/string coercion + Symfony validator integration).
- Failed tool calls return `result.isError = true` rather than JSON-RPC errors so the model receives readable diagnostics inline; only protocol-level problems (bad JSON, unknown method) become JSON-RPC `error` envelopes.
