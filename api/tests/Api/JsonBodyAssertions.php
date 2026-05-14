<?php

namespace App\Tests\Api;

/**
 * Tiny assertion helpers for narrowing values read out of JSON
 * response bodies. The underlying values come back from
 * {@see \Symfony\Contracts\HttpClient\ResponseInterface::toArray()}
 * (or `json_decode($response->getContent(), true)`) as `mixed`, which
 * PHPStan at level 10 won't let us cast / iterate / `assertCount()`
 * without an explicit guard.
 *
 * Use this in tests instead of hand-rolling `assertIsArray()` /
 * `assertIsString()` at every access site:
 *
 *   $items = $this->arrayField($body, 'items');     // array<int|string, mixed>
 *   $name  = $this->stringField($item, 'name');     // string
 *   $count = $this->intField($body, 'totalItems');  // int
 *
 * Each helper asserts the value's type (failing the test if the JSON
 * shape drifts) and returns a properly-typed value so subsequent reads
 * stay in well-typed land.
 */
trait JsonBodyAssertions
{
    /**
     * @param array<int|string, mixed> $body
     * @return array<int|string, mixed>
     */
    private function arrayField(array $body, int|string $key): array
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsArray($value);
        return $value;
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function stringField(array $body, int|string $key): string
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsString($value);
        return $value;
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function intField(array $body, int|string $key): int
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsInt($value);
        return $value;
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function boolField(array $body, int|string $key): bool
    {
        $this->assertArrayHasKey($key, $body);
        $value = $body[$key];
        $this->assertIsBool($value);
        return $value;
    }
}
