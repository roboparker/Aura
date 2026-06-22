<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CancellationFeedback;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records the "why are you leaving?" survey captured at a cancellation moment
 * (account deactivation / deletion, subscription cancellation).
 *
 * Validation and persistence are split so a caller that performs a fallible
 * external action (e.g. the billing controller calling Stripe) can validate the
 * reason up front, do the action, and only then persist the feedback — never
 * leaving a row behind for a cancellation that didn't go through.
 */
final class CancellationFeedbackRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Validate a survey body. Returns an error message when the required
     * reason is missing or not a known option, else null.
     *
     * @param array<int|string, mixed> $body
     */
    public function reasonError(array $body): ?string
    {
        $reason = $body['reason'] ?? null;
        if (!is_string($reason) || !CancellationFeedback::isValidReason($reason)) {
            return 'Please choose a reason for leaving.';
        }

        return null;
    }

    /**
     * Persist a feedback row. Assumes {@see reasonError()} already passed for
     * this body; flushes immediately so the row survives a subsequent account
     * deletion in the same request.
     *
     * @param array<int|string, mixed> $body
     */
    public function record(string $context, ?User $user, ?Space $space, array $body): CancellationFeedback
    {
        $reason = $body['reason'];
        \assert(is_string($reason));

        $feedback = new CancellationFeedback($context, $reason);
        $feedback->setUser($user);
        $feedback->setSpace($space);

        $rawComment = $body['comment'] ?? null;
        if (is_string($rawComment)) {
            $comment = trim($rawComment);
            if ('' !== $comment) {
                $feedback->setComment(mb_substr($comment, 0, CancellationFeedback::MAX_COMMENT_LENGTH));
            }
        }

        $this->em->persist($feedback);
        $this->em->flush();

        return $feedback;
    }
}
