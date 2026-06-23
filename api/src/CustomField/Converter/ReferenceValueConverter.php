<?php

declare(strict_types=1);

namespace App\CustomField\Converter;

/**
 * Produces reference IRIs. Only same-target moves are possible (e.g. a
 * single user reference ↔ a multi user reference) — a `/users/…` IRI
 * can't become a task reference — so the source IRI must already carry
 * the target subtype's path prefix. Downstream validation re-checks the
 * target exists and is in the field's space.
 */
final class ReferenceValueConverter implements CustomFieldTypeConverterInterface
{
    private const PREFIXES = [
        'user' => '/users/',
        'task' => '/tasks/',
        'project' => '/projects/',
        'page' => '/pages/',
        'discussion' => '/discussions/',
    ];

    public function targetKind(): string
    {
        return 'reference';
    }

    public function convert(mixed $value, ConversionContext $context): array
    {
        if ('reference' !== $context->fromKind || !is_string($value)) {
            return [false, null];
        }
        $prefix = self::PREFIXES[$context->toSubtype] ?? null;
        if (null !== $prefix && str_starts_with($value, $prefix)) {
            return [true, $value];
        }

        return [false, null];
    }
}
