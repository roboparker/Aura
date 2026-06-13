<?php

declare(strict_types=1);

namespace App\Security\Access;

/**
 * Actor-agnostic access policy: what a non-human (or stand-in) actor may see
 * and do, expressed as a level per content category plus optional per-item
 * overrides. The same shape backs every actor that acts with a user's
 * identity but at less-than-full power:
 *
 *  - admin impersonation consent (stored on the target User's preferences),
 *  - scoped API tokens (stored on the ApiToken),
 *  - future AI agents.
 *
 * Levels are 'none' | 'view' | 'edit' (least → most). A per-item override wins
 * over its category default. Construction is forgiving — unknown levels/shapes
 * collapse to the safe 'none' — so a malformed stored blob can't widen access.
 */
final class AccessPolicy
{
    public const NONE = 'none';
    public const VIEW = 'view';
    public const EDIT = 'edit';

    public const LEVELS = [self::NONE, self::VIEW, self::EDIT];

    public const CATEGORIES = [
        'tasks',
        'projects',
        'pages',
        'discussions',
        'comments',
        'notifications',
        'files',
    ];

    public const ITEM_TYPES = ['project', 'page', 'task', 'discussion'];

    /** @var array<string, string> item type → owning category */
    private const ITEM_TYPE_CATEGORY = [
        'project' => 'projects',
        'page' => 'pages',
        'task' => 'tasks',
        'discussion' => 'discussions',
    ];

    /**
     * @param array<array-key, mixed> $categories category => level
     * @param array<array-key, mixed> $items      type => (id => level) map; values
     *                                            are validated per-access, not by type
     */
    public function __construct(
        private array $categories,
        private array $items = [],
    ) {
    }

    public static function categoryForItemType(string $type): ?string
    {
        return self::ITEM_TYPE_CATEGORY[$type] ?? null;
    }

    /** The level for a whole category. */
    public function categoryLevel(string $category): string
    {
        $level = $this->categories[$category] ?? null;

        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : self::NONE;
    }

    /** The effective level for one item: its override, else the category default. */
    public function itemLevel(string $type, string $id): string
    {
        $perType = $this->items[$type] ?? null;
        if (is_array($perType)) {
            $level = $perType[$id] ?? null;
            if (is_string($level) && in_array($level, self::LEVELS, true)) {
                return $level;
            }
        }

        $category = self::categoryForItemType($type);

        return null !== $category ? $this->categoryLevel($category) : self::NONE;
    }

    /**
     * Partition one item type's overrides into hidden ('none') and explicitly
     * visible ('view'|'edit') id lists — used to filter collection rows.
     *
     * @return array{none: list<string>, visible: list<string>}
     */
    public function itemPartition(string $type): array
    {
        $none = [];
        $visible = [];
        $perType = $this->items[$type] ?? null;
        if (is_array($perType)) {
            foreach ($perType as $id => $level) {
                if (!is_string($id) || !is_string($level)) {
                    continue;
                }
                if (self::NONE === $level) {
                    $none[] = $id;
                } elseif (self::VIEW === $level || self::EDIT === $level) {
                    $visible[] = $id;
                }
            }
        }

        return ['none' => $none, 'visible' => $visible];
    }
}
