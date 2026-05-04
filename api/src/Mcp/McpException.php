<?php

namespace App\Mcp;

/**
 * User-facing MCP tool error. The dispatcher renders these as
 * `{ isError: true, content: [{ type: text, text: <message> }] }` so
 * the model can read the failure cause and recover, without leaking a
 * PHP stack trace.
 *
 * Codes mirror HTTP semantics for familiarity:
 *  - 400  invalid input shape
 *  - 403  caller authenticated but not authorized
 *  - 404  target not visible to caller (kept indistinguishable from
 *         403 for owner-scoped lookups, but separate when the entity
 *         is universally addressable)
 *  - 409  conflict
 *  - 422  validation failure (entity-level constraints)
 */
class McpException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }

    public static function notFound(string $what): self
    {
        return new self($what . ' not found.', 404);
    }

    public static function forbidden(string $what): self
    {
        return new self($what, 403);
    }

    public static function invalidInput(string $message): self
    {
        return new self($message, 400);
    }
}
