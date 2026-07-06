<?php

declare(strict_types=1);

namespace App\CustomField\Type\Text;

/**
 * `text.rich_text` — markdown-formatted body. Stored as the raw
 * markdown string; rendering is the PWA's job (BlockNote editor +
 * react-markdown viewer, same toolchain as Task / Board descriptions
 * and Page bodies).
 *
 * Validation rules are identical to plain text — markdown is, after
 * all, a string. Length bounds count raw characters (not rendered
 * tokens), so a 200-char `maxLength` admits 200 markdown characters
 * even if they render shorter.
 */
final class RichTextStrategy extends AbstractTextStrategy
{
    public function subtype(): string
    {
        return 'rich_text';
    }
}
