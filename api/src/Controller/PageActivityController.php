<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Page;
use App\Entity\User;
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
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $em)
    {
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

        $pageNumber = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $request->query->get('itemsPerPage', (string) self::DEFAULT_PAGE_SIZE)),
        );

        $repo = $this->em->getRepository(ActivityLog::class);
        $totalItems = (int) $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.objectClass = :class AND l.objectId = :id')
            ->setParameter('class', Page::class)
            ->setParameter('id', (string) $page->getId())
            ->getQuery()
            ->getSingleScalarResult();
        /** @var ActivityLog[] $rows */
        $rows = $repo->createQueryBuilder('l')
            ->where('l.objectClass = :class AND l.objectId = :id')
            ->setParameter('class', Page::class)
            ->setParameter('id', (string) $page->getId())
            ->orderBy('l.loggedAt', 'DESC')
            ->addOrderBy('l.version', 'DESC')
            ->setFirstResult(($pageNumber - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new JsonResponse(ActivityFeedSerializer::serialize($rows, $totalItems, $pageNumber, $perPage, $this->em));
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
