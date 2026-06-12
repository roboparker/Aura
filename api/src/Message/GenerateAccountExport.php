<?php

namespace App\Message;

/**
 * Async build request for one {@see \App\Entity\AccountExport}. Carries only
 * the row id; the handler loads it and derives everything else.
 */
final class GenerateAccountExport
{
    public function __construct(
        public readonly string $exportId,
    ) {
    }
}
