<?php

declare(strict_types=1);

namespace App\CustomField\Type\Select;

use App\CustomField\Type\MultiValueHelper;
use App\CustomField\Type\TypeViolation;

/**
 * `select.multi` — pick zero-or-more options from a fixed list. Value
 * shape is `list<string>` of option keys; duplicate keys in a single
 * value are rejected (a multi-select isn't a bag).
 *
 * `config.multi` is forced to `true` here — the subtype itself encodes
 * "many," so the config flag is redundant for select but kept for
 * shape parity with the other multi-aware kinds (the PWA reads
 * `config.multi` to decide which editor to render).
 */
final class MultiSelectStrategy extends AbstractSelectStrategy
{
    use MultiValueHelper;

    public function subtype(): string
    {
        return 'multi';
    }

    public function supportsMulti(): bool
    {
        return true;
    }

    protected function isMulti(array $config): bool
    {
        // Multi is intrinsic to this subtype — ignore whatever
        // `config.multi` says.
        return true;
    }

    protected function validateValueAgainstKeys(mixed $value, array $keys, array $config): array
    {
        $shape = $this->ensureMultiShape($value, true);
        if (null !== $shape) {
            return [$shape];
        }
        if (!is_array($value)) {
            return [new TypeViolation('', 'Expected a list of string keys.')];
        }

        $violations = [];
        $seen = [];
        foreach ($value as $i => $element) {
            $path = '[' . $i . ']';
            if (!is_string($element)) {
                $violations[] = new TypeViolation($path, 'Expected a string key.');
                continue;
            }
            if (isset($seen[$element])) {
                $violations[] = new TypeViolation($path, sprintf('Duplicate option key "%s".', $element));
                continue;
            }
            $seen[$element] = true;
            if (!in_array($element, $keys, true)) {
                $violations[] = new TypeViolation($path, sprintf('Value "%s" is not in the allowed options.', $element));
            }
        }
        return $violations;
    }
}
