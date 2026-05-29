<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\SpaceInvite;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Entity\UserInvite;
use App\Repository\SpaceInviteRepository;
use App\Repository\UserInviteRepository;
use App\Service\InviteMailer;
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
 * branches on whether the email matches an existing user:
 *   - existing user → create a `SpaceMembership` directly
 *     (status: "added")
 *   - unknown email → upsert a `UserInvite` (one per email), attach
 *     a `SpaceInvite` for this space, rotate the token, and email a
 *     signup link covering every group + space the address has been
 *     invited to (status: "invited")
 *
 * When the invitee signs up via that link, `UserPasswordHasherProcessor`
 * adds them as a `SpaceMembership` to every space attached to the
 * invite.
 */
class SpaceMemberController extends AbstractController
{
    private const INVITE_TTL_DAYS = 14;

    public function __construct(
        private EntityManagerInterface $em,
        private UserInviteRepository $userInviteRepository,
        private SpaceInviteRepository $spaceInviteRepository,
        private InviteMailer $inviteMailer,
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

        $candidate = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $candidate) {
            // Already a direct member — including a transferred admin
            // role — short-circuits with 409 so the UI can show a
            // friendly "already added" notice. Group-inherited
            // membership doesn't count as "already a direct member".
            foreach ($space->getUserMemberships() as $existing) {
                if (true === $existing->getUser()?->getId()?->equals($candidate->getId())) {
                    return $this->json(['error' => 'That user is already a member.'], 409);
                }
            }

            $membership = (new SpaceMembership())
                ->setUser($candidate)
                ->setRole(Space::ROLE_MEMBER);
            $space->addUserMembership($membership);
            $this->em->flush();

            return $this->json([
                'status' => 'added',
                'id' => (string) $candidate->getId(),
                '@id' => '/users/' . $candidate->getId(),
                'email' => $candidate->getEmail(),
            ], 200);
        }

        return $this->upsertInvite($space, $email, $user);
    }

    /**
     * Find or create the per-email `UserInvite`, attach a
     * `SpaceInvite` for this space if one isn't already there,
     * rotate the token, refresh expiry, and resend the signup
     * email. Token rotation invalidates any older link, so the most
     * recent email is always the authoritative one for that
     * invitee — same shape as `UserGroupMemberController::upsertInvite()`.
     */
    private function upsertInvite(Space $space, string $email, User $invitedBy): JsonResponse
    {
        $invite = $this->userInviteRepository->findByEmail($email);

        if (null !== $invite && null !== $invite->getAcceptedAt()) {
            // Email signed up at some point but no matching User row
            // (deleted? race?). Treat as a fresh invite.
            $invite = null;
        }

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::INVITE_TTL_DAYS));

        if (null === $invite) {
            $invite = new UserInvite($email, $tokenHash, $expiresAt);
            $this->em->persist($invite);
        } else {
            $invite->setTokenHash($tokenHash);
            $invite->setExpiresAt($expiresAt);
        }

        $existing = null !== $invite->getId()
            ? $this->spaceInviteRepository->findByInviteAndSpace($invite, $space)
            : null;
        if (null === $existing) {
            // Constructor wires the SpaceInvite into the parent's
            // collection so cascade=persist picks it up on flush.
            new SpaceInvite($invite, $space, $invitedBy);
        }

        $this->em->flush();

        $this->inviteMailer->sendSignupLink($invite, $plainToken, self::INVITE_TTL_DAYS);

        return $this->json([
            'status' => 'invited',
            'email' => $invite->getEmail(),
            'inviteId' => (string) $invite->getId(),
            'expiresAt' => $invite->getExpiresAt()->format(\DateTimeInterface::ATOM),
        ], 200);
    }
}
