<?php

declare(strict_types=1);

namespace App\Security\Permission;

/**
 * The per-space role permission vocabulary (#space-roles): one entry per
 * content category × CRUD action. A {@see \App\Entity\SpaceRole} stores a
 * `{category: {action: bool}}` matrix over these; the resolver/voter (Phase 2)
 * read it to decide what a member may do.
 *
 * Riders without their own category: task sections ride `boards`, task
 * relationships ride `tasks`, and per-board custom-field visibility rides
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

    public const PROJECTS = 'boards';
    public const TASKS = 'tasks';
    public const PAGES = 'pages';
    public const DISCUSSIONS = 'discussions';
    public const COMMENTS = 'comments';
    public const CUSTOM_FIELDS = 'custom_fields';
    public const TAGS = 'tags';
    public const GROUPS = 'groups';
    public const FILES = 'files';
    public const API_KEYS = 'api_keys';
    public const TIME_ENTRIES = 'time_entries';
    /** Invoicing + its clients (clients ride this category). Admin-reserved by default. */
    public const INVOICES = 'invoices';

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
        self::API_KEYS,
        self::TIME_ENTRIES,
        self::INVOICES,
    ];

    /**
     * Categories not granted to a plain member by default — an admin must
     * assign a custom role to unlock them. Everything else is on by default so
     * a space behaves as before until an admin tightens it.
     *
     * @var list<string>
     */
    public const ADMIN_RESERVED = [self::API_KEYS, self::INVOICES];

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
        return self::matrixOf(false);
    }

    /**
     * The seeded default for the built-in "Member" role: everything allowed
     * so a space behaves as before until an admin tightens it — EXCEPT
     * api-key management, which is admin-reserved by default. A member only
     * gains it through an explicitly-assigned custom role that grants it.
     *
     * @return array<string, array<string, bool>>
     */
    public static function fullMatrix(): array
    {
        $matrix = self::matrixOf(true);
        foreach (self::ADMIN_RESERVED as $category) {
            foreach (self::ACTIONS as $action) {
                $matrix[$category][$action] = false;
            }
        }

        return $matrix;
    }

    /**
     * The built-in "Guest" preset: read every content category + create
     * comments, nothing else.
     *
     * @return array<string, array<string, bool>>
     */
    public static function guestMatrix(): array
    {
        $matrix = self::emptyMatrix();
        foreach (self::CATEGORIES as $category) {
            // Guests never see admin-reserved surfaces (API keys, invoicing).
            if (in_array($category, self::ADMIN_RESERVED, true)) {
                continue;
            }
            $matrix[$category][self::READ] = true;
        }
        $matrix[self::COMMENTS][self::CREATE] = true;

        return $matrix;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private static function matrixOf(bool $granted): array
    {
        $matrix = [];
        foreach (self::CATEGORIES as $category) {
            foreach (self::ACTIONS as $action) {
                $matrix[$category][$action] = $granted;
            }
        }

        return $matrix;
    }
}
