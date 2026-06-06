<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\WaitlistPromoter;
use App\Service\WaitlistSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Waitlist mode surface:
 *
 *   - GET  /signup-status           public — lets the unauthenticated signup
 *                                   screen choose "Join the waitlist" vs
 *                                   "Create account".
 *   - GET  /admin/waitlist          ROLE_ADMIN — current mode, how many are
 *                                   waiting, and a (capped) list of them.
 *   - POST /admin/waitlist          ROLE_ADMIN — flip the mode. Turning it OFF
 *                                   (true → false) promotes every waitlisted
 *                                   account and emails them.
 *   - POST /admin/waitlist/promote  ROLE_ADMIN — promote one waitlisted user
 *                                   (and email them) without opening signups.
 *
 * The /admin/waitlist routes are gated to ROLE_ADMIN in security.yaml; the
 * explicit denyAccess call here is defense-in-depth so the endpoints stay
 * locked even if the access_control path rule is ever loosened.
 */
class WaitlistController extends AbstractController
{
    /** Cap on the waitlisted users surfaced in the admin list. */
    private const LIST_LIMIT = 100;

    public function __construct(
        private WaitlistSettings $settings,
        private WaitlistPromoter $promoter,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/signup-status', name: 'signup_status', methods: ['GET'])]
    public function signupStatus(): JsonResponse
    {
        return $this->json(['waitlistEnabled' => $this->settings->isEnabled()]);
    }

    #[Route('/admin/waitlist', name: 'admin_waitlist_get', methods: ['GET'])]
    public function adminGet(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $repo = $this->em->getRepository(User::class);
        $waiting = $repo->findBy(['waitlisted' => true], ['email' => 'ASC'], self::LIST_LIMIT);

        return $this->json([
            'waitlistEnabled' => $this->settings->isEnabled(),
            'waitlistedCount' => $repo->count(['waitlisted' => true]),
            'users' => array_map(static fn (User $u) => [
                'id' => (string) $u->getId(),
                'email' => $u->getEmail(),
                'givenName' => $u->getGivenName(),
                'familyName' => $u->getFamilyName(),
            ], $waiting),
        ]);
    }

    #[Route('/admin/waitlist', name: 'admin_waitlist_post', methods: ['POST'])]
    public function adminSet(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = $request->toArray();
        if (!array_key_exists('waitlistEnabled', $payload) || !is_bool($payload['waitlistEnabled'])) {
            return $this->json(['error' => 'Body must include a boolean "waitlistEnabled".'], 400);
        }
        $enabled = $payload['waitlistEnabled'];

        $wasEnabled = $this->settings->isEnabled();
        $this->settings->setEnabled($enabled);

        // Only the on → off transition releases the waitlist; an idempotent
        // "set false when already false" promotes nobody.
        $promoted = ($wasEnabled && !$enabled) ? $this->promoter->promoteAll() : 0;

        return $this->json([
            'waitlistEnabled' => $enabled,
            'promotedCount' => $promoted,
        ]);
    }

    #[Route('/admin/waitlist/promote', name: 'admin_waitlist_promote', methods: ['POST'])]
    public function adminPromote(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $payload = $request->toArray();
        $userId = $payload['userId'] ?? null;
        if (!is_string($userId) || !Uuid::isValid($userId)) {
            return $this->json(['error' => 'Body must include a valid "userId".'], 400);
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (null === $user) {
            return $this->json(['error' => 'User not found.'], 404);
        }
        if (!$user->isWaitlisted()) {
            return $this->json(['error' => 'User is not on the waitlist.'], 422);
        }

        $this->promoter->promoteOne($user);

        return $this->json([
            'promoted' => true,
            'waitlistedCount' => $this->em->getRepository(User::class)->count(['waitlisted' => true]),
        ]);
    }
}
