<?php

namespace App\Controller;

use App\Entity\Page;
use App\Entity\User;
use App\Service\ActivityFeedQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the audit history for a single page (#183). Access mirrors
 * the Page GET security expression — any space member can read; for
 * everyone else the page is invisible (404, not 403).
 *
 * Read-only — the rows are written by Gedmo's listener at flush time.
 */
class PageActivityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActivityFeedQuery $activityFeed,
    ) {
    }

    #[Route(
        '/pages/{id}/activity',
        name: 'page_activity',
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $page = $this->em->getRepository(Page::class)->find($id);
        if (null === $page || !$this->canRead($page, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        return new JsonResponse(
            $this->activityFeed->forClass(Page::class, [(string) $page->getId()], $request),
        );
    }

    private function canRead(Page $page, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        $space = $page->getSpace();
        return null !== $space && $space->hasMember($user);
    }
}
