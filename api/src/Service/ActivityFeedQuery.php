<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\ActivityFeedSerializer;
use App\Entity\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared query + pagination for the audit-history endpoints
 * (`/tasks|boards|pages|spaces/{id}/activity` and the custom-field
 * change log). Every one of those controllers parsed `page`/`itemsPerPage`
 * the same way, ran the same newest-first count + select over
 * `ActivityLog`, and handed the page to {@see ActivityFeedSerializer}.
 *
 * The only thing that varied was which (objectClass, objectIds) groups
 * scope the feed — a single entity (task/page), one board's whole tree
 * (board + its tasks), or a space's content (its boards + pages +
 * tasks). Callers pass that as a `class => ids` map; empty id lists are
 * skipped, and a feed with no groups serialises as an empty page.
 */
final class ActivityFeedQuery
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<class-string, list<string>> $objectIdsByClass
     *
     * @return array<string, mixed>
     */
    public function forObjectGroups(array $objectIdsByClass, Request $request): array
    {
        [$page, $perPage] = $this->pagination($request);

        $clauses = [];
        /** @var array<string, mixed> $params */
        $params = [];
        $i = 0;
        foreach ($objectIdsByClass as $class => $ids) {
            if ([] === $ids) {
                continue;
            }
            $clauses[] = sprintf('(l.objectClass = :class%d AND l.objectId IN (:ids%d))', $i, $i);
            $params['class' . $i] = $class;
            $params['ids' . $i] = $ids;
            ++$i;
        }

        if ([] === $clauses) {
            return ActivityFeedSerializer::serialize([], 0, $page, $perPage, $this->em);
        }

        $where = implode(' OR ', $clauses);
        $repo = $this->em->getRepository(ActivityLog::class);

        // Count and select share filters but the count must NOT carry
        // ORDER BY — Postgres rejects mixing it with COUNT(*).
        $countQb = $repo->createQueryBuilder('l')->select('COUNT(l.id)')->where($where);
        $selectQb = $repo->createQueryBuilder('l')
            ->where($where)
            ->orderBy('l.loggedAt', 'DESC')
            ->addOrderBy('l.version', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        foreach ($params as $key => $value) {
            $countQb->setParameter($key, $value);
            $selectQb->setParameter($key, $value);
        }

        $rawTotal = $countQb->getQuery()->getSingleScalarResult();
        $totalItems = is_numeric($rawTotal) ? (int) $rawTotal : 0;
        /** @var ActivityLog[] $rows */
        $rows = $selectQb->getQuery()->getResult();

        return ActivityFeedSerializer::serialize($rows, $totalItems, $page, $perPage, $this->em);
    }

    /**
     * Convenience wrapper for the common single-entity feed.
     *
     * @param class-string $objectClass
     * @param list<string>  $objectIds
     *
     * @return array<string, mixed>
     */
    public function forClass(string $objectClass, array $objectIds, Request $request): array
    {
        return $this->forObjectGroups([$objectClass => $objectIds], $request);
    }

    /**
     * @return array{int, int}
     */
    private function pagination(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $request->query->get('itemsPerPage', (string) self::DEFAULT_PAGE_SIZE)),
        );

        return [$page, $perPage];
    }
}
