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
 * Moves a page — and its full descendant subtree — into a different
 * space (#182). Pair to ProjectMoveController; same auth bar
 * (member of source AND target).
 *
 * The page's `parent` FK is cleared on move because the old parent
 * stays behind in the source space — a page can't have a parent in
 * a different space without breaking the access predicate. Children
 * follow the moved page automatically: every descendant is re-stamped
 * with the new space via a tree walk.
 *
 * Audit history is preserved automatically (Page is `Loggable`).
 */
class PageMoveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/pages/{id}/move', name: 'page_move', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $page = $this->em->getRepository(Page::class)->find($id);
        if (null === $page) {
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $sourceSpace = $page->getSpace();
        if (
            !$this->isGranted('ROLE_ADMIN')
            && (null === $sourceSpace || !$sourceSpace->hasMember($user))
        ) {
            // Hide source-membership failures behind a 404 (existence-hiding).
            return $this->json(['error' => 'Page not found.'], 404);
        }

        $payload = $request->toArray();
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

        if (null !== $sourceSpace && true === $sourceSpace->getId()?->equals($target->getId())) {
            return $this->json([
                '@id' => '/pages/' . $page->getId(),
                'id' => (string) $page->getId(),
                'space' => '/spaces/' . $target->getId(),
                'moved' => false,
            ], 200);
        }

        // Detach from any cross-space parent — the moved subtree
        // becomes top-level in the target space. Children cascade
        // through `relocateSubtree` below.
        $page->setParent(null);
        $this->relocateSubtree($page, $target);

        $this->em->flush();

        return $this->json([
            '@id' => '/pages/' . $page->getId(),
            'id' => (string) $page->getId(),
            'space' => '/spaces/' . $target->getId(),
            'moved' => true,
        ], 200);
    }

    /**
     * Re-stamps `space` on the page and every descendant in its
     * subtree. Walks the children collection breadth-first; cycles
     * are impossible because the parent FK has `ON DELETE CASCADE`
     * with the same FK pointing to a different row, and the entity
     * setter doesn't allow self-parenting.
     */
    private function relocateSubtree(Page $root, Space $target): void
    {
        $queue = [$root];
        while ([] !== $queue) {
            $node = array_shift($queue);
            $node->setSpace($target);
            // Page has no inverse children collection (parent is the
            // owning side), so query by parent FK to fan out.
            $children = $this->em->getRepository(Page::class)
                ->findBy(['parent' => $node]);
            foreach ($children as $child) {
                $queue[] = $child;
            }
        }
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
