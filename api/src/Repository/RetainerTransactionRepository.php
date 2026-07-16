<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\RetainerTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetainerTransaction>
 */
class RetainerTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetainerTransaction::class);
    }

    /** Σ deposits − Σ draws, minor units of the client's currency. */
    public function balanceFor(Client $client): int
    {
        /** @var array{deposits: string|int|null, draws: string|int|null} $row */
        $row = $this->createQueryBuilder('t')
            ->select(
                "COALESCE(SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END), 0) AS deposits",
                "COALESCE(SUM(CASE WHEN t.type = 'draw' THEN t.amount ELSE 0 END), 0) AS draws",
            )
            ->andWhere('t.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getSingleResult();

        return (int) $row['deposits'] - (int) $row['draws'];
    }

    /**
     * The ledger, newest first.
     *
     * @return list<RetainerTransaction>
     */
    public function findForClient(Client $client): array
    {
        /** @var list<RetainerTransaction> */
        return $this->createQueryBuilder('t')
            ->andWhere('t.client = :client')
            ->setParameter('client', $client)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
