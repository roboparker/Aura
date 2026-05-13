<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Re-points a discussion at a different space. Caller must be a member
 * of both the source space and the target space; non-membership on
 * either side returns 404 to match the access-extension shape.
 */
class DiscussionMoveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/discussions/{id}/move', name: 'discussion_move', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $discussion = $this->em->getRepository(Discussion::class)->find($id);
        if (null === $discussion) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }
        $sourceSpace = $discussion->getSpace();
        if (
            !$this->isGranted('ROLE_ADMIN')
            && (null === $sourceSpace || !$sourceSpace->hasMember($user))
        ) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawSpace = $payload['space'] ?? null;
        if (!is_string($rawSpace) || '' === trim($rawSpace)) {
            return $this->json(['error' => 'Target `space` IRI is required.'], 400);
        }
        $spaceId = $this->extractIdFromIri($rawSpace);
        if (null === $spaceId) {
            return $this->json(['error' => 'Invalid space IRI.'], 400);
        }

        $target = $this->em->getRepository(Space::class)->find($spaceId);
        if (null === $target) {
            return $this->json(['error' => 'Target space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$target->hasMember($user)) {
            return $this->json(['error' => 'Target space not found.'], 404);
        }

        if (null !== $sourceSpace && $sourceSpace->getId()?->equals($target->getId())) {
            return $this->json([
                '@id' => '/discussions/' . $discussion->getId(),
                'id' => (string) $discussion->getId(),
                'space' => '/spaces/' . $target->getId(),
                'moved' => false,
            ], 200);
        }

        $discussion->setSpace($target);
        $this->em->flush();

        return $this->json([
            '@id' => '/discussions/' . $discussion->getId(),
            'id' => (string) $discussion->getId(),
            'space' => '/spaces/' . $target->getId(),
            'moved' => true,
        ], 200);
    }

    /**
     * Accepts either an IRI (`/spaces/{uuid}`) or a bare UUID.
     */
    private function extractIdFromIri(string $iri): ?string
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
