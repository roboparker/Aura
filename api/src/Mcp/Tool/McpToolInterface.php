<?php

namespace App\Mcp\Tool;

use App\Entity\User;

/**
 * One MCP tool exposed over `tools/list` and dispatched by `tools/call`.
 *
 * Implementations are auto-registered via the `app.mcp_tool` tag (see
 * `_instanceof` in `services.yaml`) and surfaced in the order they're
 * iterated. Each tool runs in the context of the authenticated user;
 * authorization should be checked against `$user` rather than the
 * Symfony security helper, since the helper would also accept session
 * users from the cookie firewall.
 */
interface McpToolInterface
{
    /**
     * Stable tool identifier. Lower-snake-case, prefixed by domain
     * (`task_*`, `board_*`) so the catalog stays scannable.
     */
    public function getName(): string;

    /**
     * Human-readable summary the AI sees. Lead with what the tool does,
     * then a short cue about when to reach for it. Keep it under ~200
     * chars — long descriptions waste model context.
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing the `arguments` object.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Run the tool. Must throw {@see \App\Mcp\McpException} for
     * user-facing errors (404/403/422); any other exception is treated
     * as a 500 by the dispatcher and surfaced generically.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function invoke(array $arguments, User $user): array;
}
