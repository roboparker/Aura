<?php

namespace App\Controller;

use App\Entity\Page;
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
 * Deep-clones a page into a target space (#182). Pair to
 * ProjectCopyController.
 *
 * Scope (v1):
 *  - Title + body carry over. Title gets a " (copy)" suffix
 *    (idempotent — repeated copies don't pile up "(copy) (copy)").
 *  - Clone's `createdBy` is the current user. Fresh audit history.
 *  - Clone is top-level in the target (no parent). Even when copying
 *    within the same space we drop the parent FK so the clone reads
 *    as "a new doc" rather than a sibling of the source.
 *  - Descendant pages and PageComments are NOT copied. That's a
 *    heavier slice — pages are intentionally hierarchical, and a
 *    recursive subtree clone needs decisions about descendant
 *    ownership / cycle prevention / depth caps that don't have
 *    obvious defaults. Lands in a follow-up if/when the use case
 *    shows up.
 *
 * Auth bar: caller must be able to read the source (membership in
 * its space) AND be a member of the target space. Target defaults
 * to the source's space when no `space` is supplied.
 */
class PageCopyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/pages/{id}/copy', name: 'page_copy', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $source = $this->em->getRepository(Page::class)->find($id);
        if (null === $source) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $sourceSpace = $source->getSpace();
        if (!$this->isGranted('ROLE_ADMIN')
            && (null === $sourceSpace || !$sourceSpace->hasMember($user))
        ) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawSpace = $payload['space'] ?? null;
        $target = $sourceSpace;
        if (is_string($rawSpace) && '' !== trim($rawSpace)) {
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
        } elseif (null === $target) {
            return $this->json(['error' => 'Source page has no space and no target supplied.'], 400);
        }

        $copy = (new Page())
            ->setSpace($target)
            ->setCreatedBy($user)
            ->setTitle($this->copyTitle($source->getTitle()))
            ->setBody($source->getBody());

        $this->em->persist($copy);
        $this->em->flush();

        return $this->json([
            '@id' => '/pages/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
        ], 201);
    }

    private function copyTitle(string $sourceTitle): string
    {
        $suffix = ' (copy)';
        if (str_ends_with($sourceTitle, $suffix)) {
            return $sourceTitle;
        }
        $combined = $sourceTitle . $suffix;
        if (mb_strlen($combined) <= Page::MAX_TITLE_LENGTH) {
            return $combined;
        }
        $room = Page::MAX_TITLE_LENGTH - mb_strlen($suffix);
        if ($room <= 0) {
            return mb_substr($sourceTitle, 0, Page::MAX_TITLE_LENGTH);
        }
        return mb_substr($sourceTitle, 0, $room) . $suffix;
    }

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
