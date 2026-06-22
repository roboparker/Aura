<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Feedback;
use App\Entity\FeedbackVote;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enriches feedback reads with the two vote aggregates the board needs —
 * the net {@see Feedback::getScore() score} and the caller's own
 * {@see Feedback::getMyVote() vote} — without an N+1.
 *
 * Mirrors {@see DiscussionAggregateProvider}: delegate to the stock
 * Doctrine providers (so access scoping, pagination, filters, and item
 * 404s behave exactly as before), then run one grouped SUM over the page
 * of tickets for the score and one query for this user's votes on that
 * page. The transient fields are serialized under `feedback:read`.
 *
 * @implements ProviderInterface<Feedback>
 */
final class FeedbackAggregateProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Feedback> $collectionProvider
     * @param ProviderInterface<Feedback> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
        private EntityManagerInterface $em,
        private Security $security,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $data = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($data)) {
                $tickets = [];
                foreach ($data as $ticket) {
                    $tickets[] = $ticket;
                }
                $this->enrich($tickets);
            }

            return $data;
        }

        $item = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($item instanceof Feedback) {
            $this->enrich([$item]);
        }

        return $item;
    }

    /**
     * @param list<Feedback> $tickets
     */
    private function enrich(array $tickets): void
    {
        if ([] === $tickets) {
            return;
        }

        $scores = $this->loadScores($tickets);
        $myVotes = $this->loadMyVotes($tickets);

        foreach ($tickets as $ticket) {
            $key = (string) $ticket->getId();
            $ticket->setScore($scores[$key] ?? 0);
            $ticket->setMyVote($myVotes[$key] ?? 0);
        }
    }

    /**
     * Net vote score per ticket in one grouped query.
     *
     * @param list<Feedback> $tickets
     *
     * @return array<string, int>
     */
    private function loadScores(array $tickets): array
    {
        /** @var list<array{fid: string|null, total: int|string|null}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(v.feedback) AS fid, SUM(v.value) AS total
             FROM ' . FeedbackVote::class . ' v
             WHERE v.feedback IN (:tickets)
             GROUP BY v.feedback',
        )->setParameter('tickets', $tickets)->getResult();

        $map = [];
        foreach ($rows as $row) {
            if (null === $row['fid']) {
                continue;
            }
            $map[$row['fid']] = (int) $row['total'];
        }

        return $map;
    }

    /**
     * The current user's own vote (1 / -1) per ticket, in one query. An
     * absent row means "no vote" and is left at 0 by the caller.
     *
     * @param list<Feedback> $tickets
     *
     * @return array<string, int>
     */
    private function loadMyVotes(array $tickets): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        /** @var list<array{fid: string|null, value: int}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(v.feedback) AS fid, v.value AS value
             FROM ' . FeedbackVote::class . ' v
             WHERE v.feedback IN (:tickets) AND v.user = :user',
        )
            ->setParameter('tickets', $tickets)
            ->setParameter('user', $user)
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            if (null === $row['fid']) {
                continue;
            }
            $map[$row['fid']] = $row['value'];
        }

        return $map;
    }
}
