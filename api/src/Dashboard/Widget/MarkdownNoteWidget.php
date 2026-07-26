<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Dashboard\WidgetDefinitionInterface;
use App\Entity\Space;
use App\Entity\User;

/**
 * A free-text note pinned to the dashboard — team conventions, a link to the
 * runbook, "we ship on Thursdays".
 *
 * Also the reference implementation for the simplest possible widget: it has
 * no data source at all, so {@see data()} returns nothing and everything the
 * widget shows lives in its config. Worth keeping as the first example because
 * it proves the data endpoint is genuinely optional rather than a step every
 * widget has to fake its way through.
 */
final class MarkdownNoteWidget implements WidgetDefinitionInterface
{
    public const MAX_BODY_LENGTH = 5000;

    public function type(): string
    {
        return 'notes.markdown';
    }

    public function name(): string
    {
        return 'Note';
    }

    public function description(): string
    {
        return 'A block of text or markdown — announcements, links, or team conventions.';
    }

    public function group(): string
    {
        return 'General';
    }

    public function permissionCategory(): ?string
    {
        // Shows nothing space-scoped: the text is written by an admin on the
        // dashboard itself, so any member who can see the dashboard can see it.
        return null;
    }

    public function defaultSize(): array
    {
        return ['w' => 2, 'h' => 2];
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'title',
                'type' => 'text',
                'label' => 'Heading',
                'placeholder' => 'Optional heading',
            ],
            [
                'key' => 'body',
                'type' => 'markdown',
                'label' => 'Text',
                'placeholder' => 'Write anything…',
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $body = $config['body'] ?? '';
        if (!is_string($body)) {
            return 'Text must be a string.';
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return sprintf('Text cannot be longer than %d characters.', self::MAX_BODY_LENGTH);
        }

        $title = $config['title'] ?? '';
        if (!is_string($title)) {
            return 'Heading must be a string.';
        }

        return null;
    }

    public function data(Space $space, array $config, User $user): array
    {
        // Everything is in the config; nothing to fetch.
        return [];
    }
}
