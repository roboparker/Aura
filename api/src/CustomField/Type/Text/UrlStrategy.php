<?php

declare(strict_types=1);

namespace App\CustomField\Type\Text;

use App\CustomField\Type\TypeViolation;

/**
 * `text.url` — a syntactically valid URL string. Layered on top of the
 * text rules (multi, min/max length, pattern), with one extra scalar
 * check: the value must parse via PHP's URL filter AND name a scheme
 * we trust (`http`, `https`, or `mailto`).
 *
 * We deliberately do NOT do a network reachability check — that's a
 * different concern (and prone to false positives on transient
 * outages). Field validation only proves the string is well-formed.
 */
final class UrlStrategy extends AbstractTextStrategy
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    public function subtype(): string
    {
        return 'url';
    }

    protected function validateScalar(mixed $value, array $config): array
    {
        $violations = parent::validateScalar($value, $config);
        if (!is_string($value) || '' === trim($value)) {
            return $violations;
        }

        $trimmed = trim($value);
        if (false === filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $violations[] = new TypeViolation('', 'Value must be a valid URL.');
            return $violations;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            $violations[] = new TypeViolation('', 'URL scheme must be one of: http, https, mailto.');
        }

        return $violations;
    }
}
