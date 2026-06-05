<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enriches discussion reads with reply aggregates the PWA forum
 * surfaces need — a reply count, a last-activity timestamp, and the
 * cluster of participants (author + repliers) — without an N+1.
 *
 * Delegates to the stock Doctrine providers (so access scoping,
 * pagination, filters, and item 404s all behave exactly as before),
 * then runs two grouped queries over the page of discussions just
 * returned: one for {count, MAX(createdAt)} and one for the distinct
 * (discussion, author) pairs. The transient fields it sets on each
 * Discussion are serialized under `discussion:read`.
 *
 * @implements ProviderInterface<Discussion>
 */
final class DiscussionAggregateProvider implements ProviderInterface
{
    /** How many participant avatars the list/detail surfaces show. */
    private const PARTICIPANT_CAP = 5;

    /**
     * @param ProviderInterface<Discussion> $collectionProvider
     * @param ProviderInterface<Discussion> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
        private EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $data = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($data)) {
                $discussions = [];
                foreach ($data as $discussion) {
                    $discussions[] = $discussion;
                }
                $this->enrich($discussions);
            }

            // Return the original result so hydra pagination metadata
            // survives — the entities were mutated in place.
            return $data;
        }

        $item = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($item instanceof Discussion) {
            $this->enrich([$item]);
        }

        return $item;
    }

    /**
     * @param list<Discussion> $discussions
     */
    private function enrich(array $discussions): void
    {
        if ([] === $discussions) {
            return;
        }

        $counts = $this->loadCounts($discussions);
        $participantIds = $this->loadParticipantIds($discussions);
        $users = $this->loadUsers($participantIds);

        foreach ($discussions as $discussion) {
            $key = (string) $discussion->getId();

            $count = $counts[$key] ?? null;
            $discussion->setCommentCount(null === $count ? 0 : $count['count']);
            $discussion->setLastActivityAt(null === $count ? null : $count['last']);

            // Author always leads the participant cluster, then the
            // distinct repliers in order of first contribution.
            $ordered = [];
            $author = $discussion->getAuthor();
            if (null !== $author && null !== $author->getId()) {
                $ordered[(string) $author->getId()] = $author;
            }
            foreach ($participantIds[$key] ?? [] as $uid) {
                if (!isset($ordered[$uid]) && isset($users[$uid])) {
                    $ordered[$uid] = $users[$uid];
                }
            }

            $discussion->setParticipantCount(count($ordered));
            $discussion->setParticipants(array_slice(array_values($ordered), 0, self::PARTICIPANT_CAP));
        }
    }

    /**
     * @param list<Discussion> $discussions
     *
     * @return array<string, array{count: int, last: \DateTimeImmutable}>
     */
    private function loadCounts(array $discussions): array
    {
        /** @var list<array{did: string|null, cnt: int<0, max>, last: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.discussion) AS did, COUNT(c.id) AS cnt, MAX(c.createdAt) AS last
             FROM ' . Comment::class . ' c
             WHERE c.discussion IN (:discussions)
             GROUP BY c.discussion',
        )->setParameter('discussions', $discussions)->getResult();

        $map = [];
        foreach ($rows as $row) {
            if (null === $row['did']) {
                continue;
            }
            $map[$row['did']] = [
                'count' => $row['cnt'],
                'last' => new \DateTimeImmutable($row['last']),
            ];
        }

        return $map;
    }

    /**
     * Distinct replier user IDs per discussion, ordered by their first
     * contribution so the avatar cluster reads chronologically.
     *
     * @param list<Discussion> $discussions
     *
     * @return array<string, list<string>>
     */
    private function loadParticipantIds(array $discussions): array
    {
        /** @var list<array{did: string|null, uid: string|null, firstAt: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.discussion) AS did, IDENTITY(c.author) AS uid, MIN(c.createdAt) AS firstAt
             FROM ' . Comment::class . ' c
             WHERE c.discussion IN (:discussions)
             GROUP BY c.discussion, c.author',
        )->setParameter('discussions', $discussions)->getResult();

        usort($rows, static fn (array $a, array $b): int => $a['firstAt'] <=> $b['firstAt']);

        $map = [];
        foreach ($rows as $row) {
            if (null === $row['did'] || null === $row['uid']) {
                continue;
            }
            $map[$row['did']][] = $row['uid'];
        }

        return $map;
    }

    /**
     * Batch-load every participant user in one query, keyed by string id.
     *
     * @param array<string, list<string>> $participantIds
     *
     * @return array<string, User>
     */
    private function loadUsers(array $participantIds): array
    {
        $ids = [];
        foreach ($participantIds as $uids) {
            foreach ($uids as $uid) {
                $ids[$uid] = true;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $map = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_keys($ids)]) as $user) {
            $map[(string) $user->getId()] = $user;
        }

        return $map;
    }
}
