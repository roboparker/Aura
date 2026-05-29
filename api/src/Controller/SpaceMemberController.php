<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use App\Service\SpaceMemberAdder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Add-by-email endpoint for space members (#186). Admin-only;
 * delegates the existing-user / unknown-email branching to
 * {@see SpaceMemberAdder} so the create-with-invites path on
 * `POST /spaces` can reuse the same logic without duplicating the
 * token rotation or invite upsert.
 */
class SpaceMemberController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceMemberAdder $memberAdder,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('/spaces/{id}/members', name: 'space_add_member', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            // Hide existence from non-members; non-admin members get 403.
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can add members.'], 403);
        }

        if ($space->isPrivate()) {
            // Visibility is the structural gate here; surfacing the
            // 409 before payload validation keeps the response shape
            // independent of whatever the client sent.
            return $this->json(
                ['error' => 'Cannot add members to a private space. Switch it to shared first.'],
                409,
            );
        }

        $payload = $request->toArray();
        $email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';
        if ('' === $email) {
            return $this->json(['error' => 'Email is required.'], 400);
        }

        $emailViolations = $this->validator->validate($email, [
            new Assert\Email(),
            new Assert\Length(max: 180),
        ]);
        if (count($emailViolations) > 0) {
            return $this->json(['error' => 'Please provide a valid email address.'], 422);
        }

        $result = $this->memberAdder->add($space, $email, $user);
        if ('already_member' === $result['status']) {
            return $this->json(['error' => 'That user is already a member.'], 409);
        }

        $this->em->flush();

        if ('added' === $result['status']) {
            $candidate = $result['user'];
            return $this->json([
                'status' => 'added',
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
            ], 200);
        }

        // status === 'invited'
        $invite = $result['invite'];
        $token = $result['plainToken'];
        $this->memberAdder->sendInviteEmail($invite, $token);

        return $this->json([
            'status' => 'invited',
            'email' => $invite->getEmail(),
            'inviteId' => (string) $invite->getId(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }
}
