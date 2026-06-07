<?php

namespace App\Controller;

use App\Entity\Segment;
use App\Entity\SegmentMembership;
use App\Entity\User;
use App\Service\SegmentEvaluator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Admin-only management of a segment's manual override roster and a reach
 * preview. Segments are platform-level, so every route is ROLE_ADMIN
 * (enforced in security.yaml and re-checked here as defense in depth).
 *
 *   - POST   /segments/{id}/members        add/flip a manual include|exclude
 *   - DELETE /segments/{id}/members/{userId}  drop a manual override
 *   - GET    /segments/{id}/preview         exact reach + rule breakdown
 *
 * Unlike group/space invites there's no email-the-stranger flow: a manual
 * override only makes sense for an existing account, so an unknown email is
 * a 404.
 */
class SegmentMemberController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private SegmentEvaluator $evaluator,
    ) {
    }

    #[Route('/segments/{id}/members', name: 'segment_add_member', methods: ['POST'])]
    public function add(string $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $segment = $this->em->getRepository(Segment::class)->find($id);
        if (null === $segment) {
            return $this->json(['error' => 'Segment not found.'], 404);
        }

        $payload = $request->toArray();

        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $mode = is_string($payload['mode'] ?? null) ? $payload['mode'] : SegmentMembership::MODE_INCLUDE;
        if (!in_array($mode, SegmentMembership::MODES, true)) {
            return $this->json(['error' => 'Mode must be "include" or "exclude".'], 422);
        }

        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(),
            new Assert\Length(max: 180),
        ]);
        if (count($emailViolations) > 0) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $candidate) {
            return $this->json(['error' => 'No user found with that email.'], 404);
        }

        $existing = $this->em->getRepository(SegmentMembership::class)
            ->findOneBy(['segment' => $segment, 'user' => $candidate]);

        if (null === $existing) {
            $membership = new SegmentMembership();
            $membership->setSegment($segment);
            $membership->setUser($candidate);
            $membership->setMode($mode);
            $this->em->persist($membership);
        } else {
            // Re-POSTing flips the override's mode (include ⇄ exclude).
            $existing->setMode($mode);
        }

        $this->em->flush();

        return $this->json([
            'status' => null === $existing ? 'added' : 'updated',
            'mode' => $mode,
            'user' => [
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
            ],
        ], null === $existing ? 201 : 200);
    }

    #[Route('/segments/{id}/members/{userId}', name: 'segment_remove_member', methods: ['DELETE'])]
    public function remove(string $id, string $userId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $segment = $this->em->getRepository(Segment::class)->find($id);
        if (null === $segment) {
            return $this->json(['error' => 'Segment not found.'], 404);
        }

        $target = $this->em->getRepository(User::class)->find($userId);
        $membership = null !== $target
            ? $this->em->getRepository(SegmentMembership::class)->findOneBy(['segment' => $segment, 'user' => $target])
            : null;
        if (null === $membership) {
            return $this->json(['error' => 'That user has no manual override on this segment.'], 404);
        }

        $this->em->remove($membership);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/segments/{id}/preview', name: 'segment_preview', methods: ['GET'])]
    public function preview(string $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $segment = $this->em->getRepository(Segment::class)->find($id);
        if (null === $segment) {
            return $this->json(['error' => 'Segment not found.'], 404);
        }

        return $this->json([
            'enabled' => $segment->isEnabled(),
            'rolloutPercentage' => $segment->getRolloutPercentage(),
            'includedRoles' => $segment->getIncludedRoles(),
            'reach' => $this->evaluator->reach($segment),
        ]);
    }
}
