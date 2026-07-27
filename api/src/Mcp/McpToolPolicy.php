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
        // task relationships (subtasks / dependencies / links) ride the tasks
        // category — they're edits to the tasks involved.
        'list_task_relationships' => ['category' => 'tasks', 'write' => false],
        'link_tasks' => ['category' => 'tasks', 'write' => true],
        'unlink_tasks' => ['category' => 'tasks', 'write' => true],
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
        // Automations are board configuration, so they ride the boards scope.
        // Read-only by design — see ListAutomationsTool on why there is no
        // create_automation.
        'list_automations' => ['category' => 'boards', 'write' => false],
        'get_automation_runs' => ['category' => 'boards', 'write' => false],
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
        // expenses ride time_entries, not invoices — mirroring the REST
        // security expressions, where recording a cost is a tracking concern
        // like logging time, not visibility into what the business bills.
        'list_expenses' => ['category' => 'time_entries', 'write' => false],
        'log_expense' => ['category' => 'time_entries', 'write' => true],
        // invoicing. Clients and estimates are only addressable as billing
        // artefacts, so they ride the invoices scope.
        'list_clients' => ['category' => 'invoices', 'write' => false],
        'list_invoices' => ['category' => 'invoices', 'write' => false],
        'get_invoice' => ['category' => 'invoices', 'write' => false],
        'list_estimates' => ['category' => 'invoices', 'write' => false],
        // Analytics spans invoices AND time_entries. Gated here at the
        // stricter of the two, then filtered per metric inside the tool
        // against both the space role and the token's own policy — so a
        // token scoped to invoices can't pull time metrics through it.
        'get_analytics' => ['category' => 'invoices', 'write' => false],
        // calendar — a projection of tasks, so it rides the tasks scope.
        'list_calendar_events' => ['category' => 'tasks', 'write' => false],
        // notifications are recipient-scoped rather than space-scoped.
        'list_notifications' => ['category' => 'notifications', 'write' => false],
        'mark_notifications_read' => ['category' => 'notifications', 'write' => true],
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
