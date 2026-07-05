<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Security\Access\AccessPolicy;

/**
 * Maps each MCP tool to the content category it touches and whether it writes,
 * then resolves a tool call against the caller's {@see AccessPolicy} — the
 * same none/view/edit model used everywhere else. MCP enforcement is
 * category-level (tool arguments aren't parsed for per-item ids here).
 *
 * Every registered tool MUST appear in {@see TOOLS} — pinned by a test so a
 * newly-added tool can't silently default to "allowed under any policy".
 */
final class McpToolPolicy
{
    /** @var array<string, array{category: string, write: bool}> */
    private const TOOLS = [
        // tasks
        'get_my_tasks' => ['category' => 'tasks', 'write' => false],
        'get_task' => ['category' => 'tasks', 'write' => false],
        'list_tasks' => ['category' => 'tasks', 'write' => false],
        'search_tasks' => ['category' => 'tasks', 'write' => false],
        'create_task' => ['category' => 'tasks', 'write' => true],
        'update_task' => ['category' => 'tasks', 'write' => true],
        'delete_task' => ['category' => 'tasks', 'write' => true],
        'assign_task' => ['category' => 'tasks', 'write' => true],
        'unassign_task' => ['category' => 'tasks', 'write' => true],
        // comments (task / page / discussion comments share one category)
        'list_task_comments' => ['category' => 'comments', 'write' => false],
        'add_task_comment' => ['category' => 'comments', 'write' => true],
        'list_page_comments' => ['category' => 'comments', 'write' => false],
        'add_page_comment' => ['category' => 'comments', 'write' => true],
        'list_discussion_comments' => ['category' => 'comments', 'write' => false],
        'add_discussion_comment' => ['category' => 'comments', 'write' => true],
        // files (attachments)
        'list_files' => ['category' => 'files', 'write' => false],
        'download_file' => ['category' => 'files', 'write' => false],
        'upload_file' => ['category' => 'files', 'write' => true],
        // boards (incl. custom field schema)
        'list_projects' => ['category' => 'boards', 'write' => false],
        'get_project' => ['category' => 'boards', 'write' => false],
        'get_custom_fields' => ['category' => 'boards', 'write' => false],
        'create_project' => ['category' => 'boards', 'write' => true],
        'update_project' => ['category' => 'boards', 'write' => true],
        'delete_project' => ['category' => 'boards', 'write' => true],
        // spaces — no dedicated AccessPolicy category; spaces are the
        // container for boards, so listing them rides the boards
        // read scope (it's read-only metadata either way).
        'list_spaces' => ['category' => 'boards', 'write' => false],
        // pages
        'list_pages' => ['category' => 'pages', 'write' => false],
        'get_page' => ['category' => 'pages', 'write' => false],
        'create_page' => ['category' => 'pages', 'write' => true],
        'update_page' => ['category' => 'pages', 'write' => true],
        'delete_page' => ['category' => 'pages', 'write' => true],
        // discussions
        'list_discussions' => ['category' => 'discussions', 'write' => false],
        'get_discussion' => ['category' => 'discussions', 'write' => false],
        'create_discussion' => ['category' => 'discussions', 'write' => true],
        // tags — no dedicated category; tags are a task-tagging concern,
        // so they ride the tasks scope.
        'list_tags' => ['category' => 'tasks', 'write' => false],
        'create_tag' => ['category' => 'tasks', 'write' => true],
    ];

    /** @return array{category: string, write: bool}|null */
    public static function mapping(string $tool): ?array
    {
        return self::TOOLS[$tool] ?? null;
    }

    /** Whether a token bound to `$policy` may invoke `$tool`. */
    public static function allows(AccessPolicy $policy, string $tool): bool
    {
        $mapping = self::TOOLS[$tool] ?? null;
        if (null === $mapping) {
            // Unmapped tool — not gated (the coverage test prevents this in
            // practice).
            return true;
        }

        $level = $policy->categoryLevel($mapping['category']);
        if (AccessPolicy::EDIT === $level) {
            return true;
        }

        return AccessPolicy::VIEW === $level && false === $mapping['write'];
    }
}
