<?php

declare(strict_types=1);

namespace App\Security\Permission;

/**
 * The per-space role permission vocabulary (#space-roles): one entry per
 * content category × CRUD action. A {@see \App\Entity\SpaceRole} stores a
 * `{category: {action: bool}}` matrix over these; the resolver/voter (Phase 2)
 * read it to decide what a member may do.
 *
 * Riders without their own category: task sections ride `projects`, task
 * relationships ride `tasks`, and per-project custom-field visibility rides
 * `custom_fields`.
 */
final class SpacePermission
{
    public const CREATE = 'create';
    public const READ = 'read';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /** @var list<string> */
    public const ACTIONS = [self::CREATE, self::READ, self::UPDATE, self::DELETE];

    public const PROJECTS = 'projects';
    public const TASKS = 'tasks';
    public const PAGES = 'pages';
    public const DISCUSSIONS = 'discussions';
    public const COMMENTS = 'comments';
    public const CUSTOM_FIELDS = 'custom_fields';
    public const TAGS = 'tags';
    public const GROUPS = 'groups';
    public const FILES = 'files';

    /** @var list<string> */
    public const CATEGORIES = [
        self::PROJECTS,
        self::TASKS,
        self::PAGES,
        self::DISCUSSIONS,
        self::COMMENTS,
        self::CUSTOM_FIELDS,
        self::TAGS,
        self::GROUPS,
        self::FILES,
    ];

    public static function isCategory(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    public static function isAction(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }

    /**
     * A fully-false permission matrix (every category × action denied) — the
     * starting point for a new role in the editor.
     *
     * @return array<string, array<string, bool>>
     */
    public static function emptyMatrix(): array
    {
        $matrix = [];
        foreach (self::CATEGORIES as $category) {
            foreach (self::ACTIONS as $action) {
                $matrix[$category][$action] = false;
            }
        }

        return $matrix;
    }
}
