<?php

namespace App\Repository;

use App\Entity\Space;
use App\Entity\TimesheetApproval;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TimesheetApproval>
 */
class TimesheetApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TimesheetApproval::class);
    }

    /** The (unique) approval covering one member's week, if any. */
    public function findForWeek(Space $space, User $user, \DateTimeImmutable $weekStart): ?TimesheetApproval
    {
        return $this->findOneBy(['space' => $space, 'user' => $user, 'weekStart' => $weekStart]);
    }

    /**
     * The Monday (UTC date) of the week containing a timestamp — the shared
     * bucketing rule for approvals and the entry lock.
     */
    public static function weekStartOf(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d'),
            new \DateTimeZone('UTC'),
        );
        assert(false !== $day);
        $dow = (int) $day->format('N'); // 1 (Mon) … 7 (Sun)

        return $day->modify(sprintf('-%d days', $dow - 1));
    }

    /**
     * True when the entry's week is frozen by a pending/approved approval
     * for its tracker — the guard TimeEntry create/edit/delete run through.
     */
    public function weekIsLocked(Space $space, User $user, \DateTimeImmutable $startedAt): bool
    {
        $approval = $this->findForWeek($space, $user, self::weekStartOf($startedAt));

        return null !== $approval && $approval->locksWeek();
    }

    /**
     * A space's approvals, optionally filtered by status, newest week first.
     *
     * @return list<TimesheetApproval>
     */
    public function findForSpace(Space $space, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.space = :space')
            ->setParameter('space', $space)
            ->orderBy('t.weekStart', 'DESC')
            ->addOrderBy('t.submittedAt', 'DESC');
        if (null !== $status) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        /** @var list<TimesheetApproval> */
        return $qb->getQuery()->getResult();
    }
}
