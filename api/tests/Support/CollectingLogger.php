<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Test double: a PSR-3 sink that keeps every record in memory.
 *
 * Simpler than mocking LoggerInterface's eight level shortcuts, and it lets a
 * test assert on the level a service chose — which is the point when the level
 * itself is the contract (an operational alarm has to be loud enough to reach
 * the prod handlers).
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        // PSR-3 types $level as mixed; AbstractLogger's shortcuts always pass a
        // LogLevel string, so anything else is a caller we don't care about.
        $this->records[] = [
            'level' => is_string($level) ? $level : 'unknown',
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
