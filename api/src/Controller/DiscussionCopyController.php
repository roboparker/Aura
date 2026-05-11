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
 * Deep-clones a discussion into a target project (#182). Pair to
 * ProjectCopyController + PageCopyController.
 *
 * Like the discussion move endpoint, the target is a project rather
 * than a space — discussions are project-scoped and inherit their
 * space from there. The clone's denormalised `space` follows the
 * target project's space automatically (set explicitly here because
 * the `syncSpaceFromProject` PrePersist hook only fires when space
 * is null at insert time).
 *
 * Scope (v1):
 *  - Title + body + category carry over. Title gets a " (copy)"
 *    suffix (idempotent).
 *  - Clone's `author` is the current user — fresh audit history.
 *  - `isPinned` and `isLocked` reset to false on the clone. Those
 *    are moderation states tied to the source thread, not to the
 *    content itself.
 *  - Comments on the source are NOT copied. The cloned thread
 *    starts empty.
 *
 * Auth: caller must be able to read the source discussion (membership
 * in its project's space) AND be a member of the target project's
 * space. Target defaults to the source project when no body is
 * supplied.
 */
class DiscussionCopyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/discussions/{id}/copy', name: 'discussion_copy', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $source = $this->em->getRepository(Discussion::class)->find($id);
        if (null === $source) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }
        $sourceProject = $source->getProject();
        if (!$this->isGranted('ROLE_ADMIN')
            && (null === $sourceProject || !$sourceProject->isAccessibleBy($user))
        ) {
            return $this->json(['error' => 'Discussion not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawProject = $payload['project'] ?? null;
        $target = $sourceProject;
        if (is_string($rawProject) && '' !== trim($rawProject)) {
            $projectId = $this->extractIdFromIri($rawProject);
            if (null === $projectId) {
                return $this->json(['error' => 'Invalid project IRI.'], 400);
            }
            $target = $this->em->getRepository(Project::class)->find($projectId);
            if (null === $target) {
                return $this->json(['error' => 'Target project not found.'], 404);
            }
            if (!$this->isGranted('ROLE_ADMIN') && !$target->isAccessibleBy($user)) {
                return $this->json(['error' => 'Target project not found.'], 404);
            }
        } elseif (null === $target) {
            return $this->json(['error' => 'Source discussion has no project and no target supplied.'], 400);
        }

        $copy = (new Discussion())
            ->setProject($target)
            ->setSpace($target->getSpace())
            ->setAuthor($user)
            ->setTitle($this->copyTitle($source->getTitle()))
            ->setBody($source->getBody())
            ->setCategory($source->getCategory());
        // isPinned / isLocked default to false on a fresh Discussion;
        // we deliberately don't copy them since they're moderation
        // state tied to the source thread.

        $this->em->persist($copy);
        $this->em->flush();

        return $this->json([
            '@id' => '/discussions/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'project' => '/projects/' . $target->getId(),
            'space' => null !== $target->getSpace()
                ? '/spaces/' . $target->getSpace()->getId()
                : null,
        ], 201);
    }

    private function copyTitle(string $sourceTitle): string
    {
        $suffix = ' (copy)';
        if (str_ends_with($sourceTitle, $suffix)) {
            return $sourceTitle;
        }
        $combined = $sourceTitle . $suffix;
        if (mb_strlen($combined) <= Discussion::MAX_TITLE_LENGTH) {
            return $combined;
        }
        $room = Discussion::MAX_TITLE_LENGTH - mb_strlen($suffix);
        if ($room <= 0) {
            return mb_substr($sourceTitle, 0, Discussion::MAX_TITLE_LENGTH);
        }
        return mb_substr($sourceTitle, 0, $room) . $suffix;
    }

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
