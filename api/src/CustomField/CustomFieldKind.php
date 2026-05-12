<?php

declare(strict_types=1);

namespace App\CustomField;

/**
 * Top-level custom-field kinds. The (kind, subtype) pair identifies a
 * concrete strategy; the kind alone drives UI dispatch (which editor
 * family to render) and SQL filters that don't care about subtype.
 */
enum CustomFieldKind: string
{
    case BOOLEAN = 'boolean';
    case TEXT = 'text';
    case NUMERIC = 'numeric';
    case DATE = 'date';
    case SELECT = 'select';
    case REFERENCE = 'reference';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
