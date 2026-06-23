<?php

namespace App\Controller;

use App\Entity\FeedbackVote;
use App\Entity\User;
use App\Repository\FeedbackRepository;
use App\Repository\FeedbackVoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Up/down voting on a feedback ticket. Exactly one vote per user per
 * ticket, expressed as a toggle:
 *
 *   - no vote yet            → create the vote          (added)
 *   - same direction again   → clear it                 (cleared)
 *   - opposite direction     → flip it                  (updated)
 *
 * The (feedback, user) unique constraint on {@see FeedbackVote} backs the
 * invariant; this controller is the only writer. Any ROLE_USER can vote
 * on any ticket — the board is instance-level, so there's no membership
 * gate, only authentication. The response carries the recomputed net
 * score and the caller's resulting own vote so the PWA can update the
 * control without a re-fetch.
 */
class FeedbackVoteController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private FeedbackRepository $feedbackRepository,
        private FeedbackVoteRepository $voteRepository,
    ) {
    }

    #[Route('/feedback/{id}/vote', name: 'feedback_vote', methods: ['POST'])]
    public function vote(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Feedback not found.'], 404);
        }

        $feedback = $this->feedbackRepository->find($id);
        if (null === $feedback) {
            return $this->json(['error' => 'Feedback not found.'], 404);
        }

        $payload = $request->toArray();
        $value = $payload['value'] ?? null;
        if (!is_int($value) || !in_array($value, FeedbackVote::VALUES, true)) {
            return $this->json(['error' => 'Vote value must be 1 or -1.'], 422);
        }

        $existing = $this->voteRepository->findOneForUser($feedback, $user);

        if (null === $existing) {
            $vote = new FeedbackVote();
            $vote->setFeedback($feedback);
            $vote->setUser($user);
            $vote->setValue($value);
            $this->em->persist($vote);
            $status = 'added';
            $myVote = $value;
        } elseif ($existing->getValue() === $value) {
            // Re-voting the same direction clears the vote.
            $this->em->remove($existing);
            $status = 'cleared';
            $myVote = 0;
        } else {
            $existing->setValue($value);
            $status = 'updated';
            $myVote = $value;
        }

        $this->em->flush();

        return $this->json([
            'status' => $status,
            'myVote' => $myVote,
            'score' => $this->voteRepository->scoreFor($feedback),
        ]);
    }
}
