<?php

declare(strict_types=1);

namespace App\CustomField\Type;

/**
 * Type-strategy violation: a path relative to the definition or value
 * payload, a human-readable message, and optional message parameters
 * for ConstraintViolationBuilder. Strategies return lists of these so
 * the surrounding validator can attach them in one place.
 */
final readonly class TypeViolation
{
    /**
     * @param array<string, scalar> $parameters
     */
    public function __construct(
        public string $path,
        public string $message,
        public array $parameters = [],
    ) {
    }
}
