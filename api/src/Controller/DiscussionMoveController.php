<?php

namespace App\Controller;

use App\Entity\Discussion;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Re-points a discussion at a different project (#182). Discussions
 * live inside a project — so unlike the Project / Page move endpoints
 * which take a target space directly, this one takes a target
 * `project`. The discussion's denormalised `space` follows the new
 * project automatically.
 *
 * Auth bar mirrors the rest of the move family: caller must be able
 * to read the source discussion (which already implies source space
 * membership via DiscussionAccessExtension) AND be a member of the
 * target project's space. Non-membership on either side returns 404
 * to match the access-extension existence-hiding shape.
 *
 * The denormalised `space` is re-stamped explicitly here because the
 * `syncSpaceFromProject` PrePersist hook only fills the cache on
 * insert — without this, the cached space would drift out of sync
 * with the discussion's actual home after a move.
 *
 * Audit history is preserved automatically: Discussion is `Loggable`,
 * so the `project` + `space` changes land as a normal update entry.
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
        $sourceProject = $discussion->getProject();
        if (!$this->isGranted('ROLE_ADMIN')
            && (null === $sourceProject || !$sourceProject->isAccessibleBy($user))
        ) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawProject = $payload['project'] ?? null;
        if (!is_string($rawProject) || '' === trim($rawProject)) {
            return $this->json(['error' => 'Target `project` IRI is required.'], 400);
        }
        $projectId = $this->extractIdFromIri($rawProject);
        if (null === $projectId) {
            return $this->json(['error' => 'Invalid project IRI.'], 400);
        }

        $target = $this->em->getRepository(Project::class)->find($projectId);
        if (null === $target) {
            return $this->json(['error' => 'Target project not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$target->isAccessibleBy($user)) {
            // Hide target existence to mirror ProjectAccessExtension.
            return $this->json(['error' => 'Target project not found.'], 404);
        }

        if (null !== $sourceProject && $sourceProject->getId()?->equals($target->getId())) {
            return $this->json([
                '@id' => '/discussions/' . $discussion->getId(),
                'id' => (string) $discussion->getId(),
                'project' => '/projects/' . $target->getId(),
                'moved' => false,
            ], 200);
        }

        $discussion->setProject($target);
        // Re-stamp the denormalised space so the cache tracks the new
        // owner — PrePersist's `syncSpaceFromProject` only fires on
        // insert, not on update.
        $discussion->setSpace($target->getSpace());

        $this->em->flush();

        return $this->json([
            '@id' => '/discussions/' . $discussion->getId(),
            'id' => (string) $discussion->getId(),
            'project' => '/projects/' . $target->getId(),
            'space' => null !== $target->getSpace()
                ? '/spaces/' . $target->getSpace()->getId()
                : null,
            'moved' => true,
        ], 200);
    }

    /**
     * Accepts either an IRI (`/projects/{uuid}`) or a bare UUID — the
     * PWA serialises projects as IRIs, but tests and curl-by-hand often
     * pass the raw id, so be tolerant.
     */
    private function extractIdFromIri(string $iri): ?string
    {
        $trimmed = trim($iri);
        if (Uuid::isValid($trimmed)) {
            return $trimmed;
        }
        if (1 === preg_match('#^/projects/([0-9a-f-]+)$#i', $trimmed, $m) && Uuid::isValid($m[1])) {
            return $m[1];
        }
        return null;
    }
}
