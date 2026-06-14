<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\RecurrenceCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * `POST /recurrence-preview` — computes the next occurrence dates for a
 * candidate recurrence rule so the editor can render its "next N
 * occurrences" preview without persisting anything. Delegates to the same
 * {@see RecurrenceCalculator} the spawn logic uses, so the preview can
 * never disagree with what completing the task would actually create.
 *
 * Body: `{ "dueDate": ISO-8601, "rule": {…}, "count": int? }`.
 * Response: `{ "occurrences": [ISO-8601, …] }`.
 *
 * A malformed rule or missing due date yields an empty list rather than an
 * error — the preview is advisory, and the real validation happens on save.
 */
final class RecurrencePreviewController extends AbstractController
{
    private const MAX_PREVIEW = 10;

    public function __construct(
        private readonly RecurrenceCalculator $recurrence,
    ) {
    }

    #[Route('/recurrence-preview', name: 'recurrence_preview', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $payload = $request->toArray();
        $rule = $payload['rule'] ?? null;
        $dueRaw = $payload['dueDate'] ?? null;
        $count = $payload['count'] ?? 3;

        $limit = is_int($count) ? max(1, min(self::MAX_PREVIEW, $count)) : 3;

        if (!is_array($rule) || !is_string($dueRaw) || '' === $dueRaw) {
            return new JsonResponse(['occurrences' => []]);
        }

        try {
            $due = new \DateTimeImmutable($dueRaw);
        } catch (\Exception) {
            return new JsonResponse(['occurrences' => []]);
        }

        // Cap the preview at the remaining future occurrences for a
        // count-based end (count counts the anchor itself).
        $ends = $rule['ends'] ?? null;
        if (is_array($ends) && ($ends['type'] ?? null) === 'count') {
            $total = is_int($ends['count'] ?? null) ? $ends['count'] : 1;
            $limit = max(0, min($limit, $total - 1));
        }

        $occurrences = $this->recurrence->nextOccurrences($due, $rule, $limit);

        return new JsonResponse([
            'occurrences' => array_map(
                static fn (\DateTimeImmutable $d): string => $d->format(\DateTimeInterface::ATOM),
                $occurrences,
            ),
        ]);
    }
}
