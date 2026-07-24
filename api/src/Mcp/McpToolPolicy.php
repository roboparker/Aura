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
        // comments (task / page comments share one category)
        'list_task_comments' => ['category' => 'comments', 'write' => false],
        'add_task_comment' => ['category' => 'comments', 'write' => true],
        'list_page_comments' => ['category' => 'comments', 'write' => false],
        'add_page_comment' => ['category' => 'comments', 'write' => true],
        // files (attachments)
        'list_files' => ['category' => 'files', 'write' => false],
        'download_file' => ['category' => 'files', 'write' => false],
        'upload_file' => ['category' => 'files', 'write' => true],
        // boards (incl. custom field schema)
        'list_boards' => ['category' => 'boards', 'write' => false],
        'get_board' => ['category' => 'boards', 'write' => false],
        'get_custom_fields' => ['category' => 'boards', 'write' => false],
        'create_board' => ['category' => 'boards', 'write' => true],
        'update_board' => ['category' => 'boards', 'write' => true],
        'delete_board' => ['category' => 'boards', 'write' => true],
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
        // tags — no dedicated category; tags are a task-tagging concern,
        // so they ride the tasks scope.
        'list_tags' => ['category' => 'tasks', 'write' => false],
        'create_tag' => ['category' => 'tasks', 'write' => true],
        // time tracking. `list_projects` returns *client* projects (the thing
        // time is tracked against), so it rides time_entries rather than
        // boards — a token scoped to tasks has no business reading rate cards.
        'list_projects' => ['category' => 'time_entries', 'write' => false],
        'list_time_entries' => ['category' => 'time_entries', 'write' => false],
        'log_time' => ['category' => 'time_entries', 'write' => true],
        'start_timer' => ['category' => 'time_entries', 'write' => true],
        'stop_timer' => ['category' => 'time_entries', 'write' => true],
        // invoicing. Clients are only addressable as billing counterparties,
        // so they ride the invoices scope.
        'list_clients' => ['category' => 'invoices', 'write' => false],
        'list_invoices' => ['category' => 'invoices', 'write' => false],
        'get_invoice' => ['category' => 'invoices', 'write' => false],
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
