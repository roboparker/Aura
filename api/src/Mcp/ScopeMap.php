<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Maps each MCP tool to the resource scope a token must carry to invoke it,
 * and resolves whether a token's scope set permits a tool.
 *
 * The wire/UI scope vocabulary is resource-oriented
 * (`read:tasks`, `write:tasks`, …) rather than per-tool — see
 * {@see \App\Entity\ApiToken::SCOPE_VOCABULARY}. `admin` is the superset.
 * An empty scope list means "no restriction" (the common personal-token
 * case), preserved for back-compat. A write scope implies its read scope.
 *
 * Every registered tool MUST appear in {@see TOOL_SCOPES} — pinned by a test
 * so a newly-added tool can't silently default to "allowed for any scope".
 */
final class ScopeMap
{
    /** @var array<string, string> tool name → required scope */
    private const TOOL_SCOPES = [
        // tasks (incl. task comments + attachments, which hang off tasks)
        'get_my_tasks' => 'read:tasks',
        'get_task' => 'read:tasks',
        'list_tasks' => 'read:tasks',
        'search_tasks' => 'read:tasks',
        'list_task_comments' => 'read:tasks',
        'list_files' => 'read:tasks',
        'download_file' => 'read:tasks',
        'create_task' => 'write:tasks',
        'update_task' => 'write:tasks',
        'delete_task' => 'write:tasks',
        'assign_task' => 'write:tasks',
        'unassign_task' => 'write:tasks',
        'add_task_comment' => 'write:tasks',
        'upload_file' => 'write:tasks',
        // projects (incl. custom field schema)
        'list_projects' => 'read:projects',
        'get_project' => 'read:projects',
        'get_custom_fields' => 'read:projects',
        'create_project' => 'write:projects',
        'update_project' => 'write:projects',
        'delete_project' => 'write:projects',
    ];

    public static function requiredScope(string $tool): ?string
    {
        return self::TOOL_SCOPES[$tool] ?? null;
    }

    /**
     * Whether a token carrying `$scopes` may invoke `$tool`.
     *
     * @param string[] $scopes
     */
    public static function isToolAllowed(array $scopes, string $tool): bool
    {
        if ([] === $scopes) {
            return true;
        }
        if (in_array('admin', $scopes, true)) {
            return true;
        }

        $required = self::requiredScope($tool);
        if (null === $required) {
            // Unmapped tool — not gated (keeps older tokens working). The
            // tool-coverage test ensures this branch isn't reached in prod.
            return true;
        }
        if (in_array($required, $scopes, true)) {
            return true;
        }
        // write:X implies read:X.
        if (str_starts_with($required, 'read:')) {
            $writeEquivalent = 'write:' . substr($required, 5);
            if (in_array($writeEquivalent, $scopes, true)) {
                return true;
            }
        }

        return false;
    }
}
