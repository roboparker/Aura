<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for controllers that hand-read a JSON request body.
 * `jsonBody()` decodes to an associative array (returning [] for an empty
 * or non-object body rather than throwing, which suits the step-up /
 * account endpoints that accept an optional body); `stringField()` plucks
 * a string field defaulting to '' — avoiding the `cast.string` PHPStan
 * violation that `(string) ($body[$key] ?? '')` trips.
 */
trait JsonRequestTrait
{
    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        return is_string($value) ? $value : '';
    }
}
