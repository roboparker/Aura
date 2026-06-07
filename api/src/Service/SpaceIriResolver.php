<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Parses the `space` value accepted by the copy/move controllers. Callers
 * may pass either a full IRI (`/spaces/{uuid}`) or a bare UUID; both resolve
 * to the space id, anything else to null. Pulled out of the six copy/move
 * controllers that each carried a byte-identical `extractIdFromIri()`.
 */
final class SpaceIriResolver
{
    private function __construct()
    {
    }

    public static function extractId(string $iri): ?string
    {
        $trimmed = trim($iri);
        if (Uuid::isValid($trimmed)) {
            return $trimmed;
        }
        if (1 === preg_match('#^/spaces/([0-9a-f-]+)$#i', $trimmed, $m) && Uuid::isValid($m[1])) {
            return $m[1];
        }
        return null;
    }
}
