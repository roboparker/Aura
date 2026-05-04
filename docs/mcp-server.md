# MCP server

Aura ships an integrated [Model Context Protocol](https://modelcontextprotocol.io/) server so AI assistants like Claude Desktop and Claude Code can read and edit tasks, projects, and comments through the same authorization rules as the PWA.

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
Authorization: Bearer aura_pat_<secret>
```

### Minting a token

Authenticate to the PWA, then `POST /api-tokens` with a name (and optional `scopes` allow-list and `expiresAt`):

```bash
curl -X POST https://your-aura/api-tokens \
  -H 'Content-Type: application/ld+json' \
  -H 'Cookie: PHPSESSID=...' \
  -d '{"name": "Local CLI"}'
```

The response includes a one-shot `plainToken` field — copy it; subsequent `GET /api-tokens` calls do not return it again. The persisted database row stores only the sha256 hash.

`scopes` is an array of MCP tool names. The empty array (default) means "all tools." Narrow tokens to e.g. `["get_task", "list_tasks"]` for read-only access.

`POST /api-tokens` and `GET /api-tokens` use the cookie-based PWA session (firewall: `main`); the `/mcp` firewall is independent and accepts only Bearer tokens.

### Revoking

`DELETE /api-tokens/{id}` revokes the token. Item-level filtering means you cannot see or revoke another user's token IDs.

## Available tools

| Category    | Tool                                                                           |
| ----------- | ------------------------------------------------------------------------------ |
| Task        | `create_task`, `get_task`, `update_task`, `delete_task`, `list_tasks`, `search_tasks` |
| Project     | `create_project`, `get_project`, `update_project`, `delete_project`, `list_projects` |
| Assignment  | `assign_task`, `unassign_task`, `get_my_tasks`                                 |
| Comment     | `add_comment`, `list_comments`                                                 |
| File        | `upload_file`, `list_files`, `download_file`                                   |
| Custom field| `get_custom_fields`                                                            |

Call `tools/list` to inspect each tool's JSON Schema. Tools execute as the user that owns the bearer token; visibility, edit, and delete rules mirror the existing API Platform `security:` expressions on each entity.

> **Custom field values** — `set_custom_field` is intentionally not yet exposed: the underlying `CustomFieldValue` entity isn't built (only definitions, from #84). `get_custom_fields` returns the project's defined fields so MCP callers can prepare for the value-write surface once it lands.

## Errors

The dispatcher distinguishes JSON-RPC errors (malformed envelopes, unknown methods → `error.code` per the spec) from tool errors (invalid input, 404/403/422 → success envelope with `result.isError = true`). Tool errors include a human-readable message in `result.content[0].text` so the model can recover and retry.

## Client config example

### Claude Desktop / Claude Code (`mcpServers`)

```json
{
  "mcpServers": {
    "aura": {
      "url": "https://your-aura-instance.com/mcp",
      "headers": {
        "Authorization": "Bearer aura_pat_PASTE_YOUR_TOKEN_HERE"
      }
    }
  }
}
```

For local dev (default `compose.yaml` ports):

```json
{
  "mcpServers": {
    "aura": {
      "url": "https://localhost/mcp",
      "headers": { "Authorization": "Bearer aura_pat_PASTE_YOUR_TOKEN_HERE" }
    }
  }
}
```

A copy-paste-ready file lives at [`api/config/mcp-server.json`](../api/config/mcp-server.json).

## Implementation notes

- Token storage mirrors `PasswordResetToken` and `UserInvite`: 32 random bytes, prefixed `aura_pat_`, hashed with sha256 on persist. The cleartext is shown once and never again.
- The `/mcp` firewall is stateless and declared before `main`, so `json_login` and the SchebTwoFactorBundle listener never see `/mcp` traffic.
- Tools live under `api/src/Mcp/Tool/` and are auto-registered via the `app.mcp_tool` tag (`_instanceof` in `services.yaml`). To add a new tool, implement `App\Mcp\Tool\McpToolInterface` — that's the entire wiring.
- All tools share `App\Mcp\McpAuthorization` (rules duplicated from the entity `security:` expressions), `App\Mcp\McpEntitySerializer` (plain-array shape, no JSON-LD envelope), and `App\Mcp\McpInputHelper` (UUID/date/string coercion + Symfony validator integration).
- Failed tool calls return `result.isError = true` rather than JSON-RPC errors so the model receives readable diagnostics inline; only protocol-level problems (bad JSON, unknown method) become JSON-RPC `error` envelopes.
