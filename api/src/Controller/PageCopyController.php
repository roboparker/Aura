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
 * Scope:
 *  - Title + body carry over. Title gets a " (copy)" suffix
 *    (idempotent — repeated copies don't pile up "(copy) (copy)").
 *  - Clone's `createdBy` is the current user. Fresh audit history.
 *  - Clone root is top-level in the target (no parent). Even when
 *    copying within the same space we drop the parent FK so the
 *    clone reads as "a new doc" rather than a sibling of the source.
 *  - Comments are NOT copied. The cloned page tree starts as a
 *    fresh thread surface.
 *  - Descendants: opt-in via `includeDescendants: true` on the
 *    body. When set, a breadth-first walk clones every descendant
 *    with a parent that points at the clone of its source parent —
 *    so the source's hierarchy is mirrored verbatim inside the
 *    clone. Only the root carries the ' (copy)' suffix; descendants
 *    keep their original titles (a "(copy)"-on-every-row tree would
 *    be noisy). Each descendant clone's createdBy is the caller.
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
        if (
            !$this->isGranted('ROLE_ADMIN')
            && (null === $sourceSpace || !$sourceSpace->hasMember($user))
        ) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $payload = $request->toArray();
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

        // Optional subtree clone (#182 deep-copy). Opt-in via
        // `includeDescendants: true`. Walks the source subtree
        // breadth-first, cloning each descendant with a parent that
        // points at its newly-created ancestor (so the source's
        // hierarchy is preserved verbatim inside the clone). Only
        // the root of the clone is top-level in the target; every
        // other clone has a clone-parent. Title gets the ' (copy)'
        // suffix only on the root — keeping it on every descendant
        // would make the rendered tree noisy.
        $descendantsCloned = 0;
        if (true === ($payload['includeDescendants'] ?? false)) {
            $descendantsCloned = $this->cloneDescendants($source, $copy, $target, $user);
        }

        $this->em->flush();

        return $this->json([
            '@id' => '/pages/' . $copy->getId(),
            'id' => (string) $copy->getId(),
            'title' => $copy->getTitle(),
            'space' => '/spaces/' . $target->getId(),
            'descendantsCloned' => $descendantsCloned,
        ], 201);
    }

    /**
     * Breadth-first walk of the source's subtree. Each visited node
     * becomes a Page clone whose `parent` points at the clone of its
     * source parent (so the cloned tree mirrors the source's shape).
     * Returns the count of descendants cloned (excludes the root).
     */
    private function cloneDescendants(Page $sourceRoot, Page $cloneRoot, Space $target, User $user): int
    {
        $repo = $this->em->getRepository(Page::class);
        $cloneBySource = [(string) $sourceRoot->getId() => $cloneRoot];
        $queue = [$sourceRoot];
        $count = 0;

        while ([] !== $queue) {
            $node = array_shift($queue);
            $children = $repo->findBy(['parent' => $node], ['position' => 'ASC']);
            foreach ($children as $child) {
                $clonedParent = $cloneBySource[(string) $node->getId()] ?? null;
                if (null === $clonedParent) {
                    continue;
                }
                $clone = (new Page())
                    ->setSpace($target)
                    ->setCreatedBy($user)
                    ->setTitle($child->getTitle())
                    ->setBody($child->getBody())
                    ->setParent($clonedParent)
                    ->setPosition($child->getPosition());
                $this->em->persist($clone);
                $cloneBySource[(string) $child->getId()] = $clone;
                $queue[] = $child;
                ++$count;
            }
        }
        return $count;
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
        // $suffix is a literal ' (copy)' (7 chars), so MAX_TITLE_LENGTH -
        // strlen($suffix) is always positive — no need for a fallback branch.
        $room = Page::MAX_TITLE_LENGTH - mb_strlen($suffix);
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
