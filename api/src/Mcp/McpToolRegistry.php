<?php

namespace App\Mcp;

use App\Mcp\Tool\McpToolInterface;

/**
 * Lookup table over the MCP tools available in this build. Built once
 * per request from the autoconfigured `app.mcp_tool` services, then
 * exposed as a name → tool map plus an iteration order matching
 * registration so `tools/list` is stable across reloads.
 */
final class McpToolRegistry
{
    /** @var array<string, McpToolInterface> */
    private readonly array $byName;

    /**
     * @param iterable<McpToolInterface> $tools
     */
    public function __construct(iterable $tools)
    {
        $byName = [];
        foreach ($tools as $tool) {
            $name = $tool->getName();
            if (isset($byName[$name])) {
                throw new \LogicException(sprintf(
                    'Duplicate MCP tool name "%s" — each tool must declare a unique getName().',
                    $name,
                ));
            }
            $byName[$name] = $tool;
        }
        $this->byName = $byName;
    }

    public function get(string $name): ?McpToolInterface
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * @return McpToolInterface[]
     */
    public function all(): array
    {
        return array_values($this->byName);
    }
}
