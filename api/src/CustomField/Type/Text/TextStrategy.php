<?php

declare(strict_types=1);

namespace App\CustomField\Type\Text;

/**
 * `text.text` — plain single-line or short paragraph text. The default
 * choice when a project just needs a free-form string field; richer
 * formatting is `text.rich_text`, validated URLs are `text.url`.
 */
final class TextStrategy extends AbstractTextStrategy
{
    public function subtype(): string
    {
        return 'text';
    }
}
