<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\Expense;
use App\Entity\TimeEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes budget consumption for projects (#651) in one grouped query —
 * shared by the read-side provider (progress bars) and the nightly alert
 * sweep so the two can never disagree.
 *
 * An `hours` budget counts every completed entry's duration (minutes); a
 * `fees` budget counts the billable amount (Σ seconds × snapshotted rate /
 * 3600, minor units) **plus billable expenses** (#666) — a cheap SQL
 * approximation of the invoice math, close enough for progress + alerts.
 */
final class ProjectBudgetCalculator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Spent-per-project for the given rows, keyed by project id:
     * `['seconds' => total tracked, 'fees' => billable minor units]`.
     *
     * @param list<Project> $projects
     *
     * @return array<string, array{seconds: int, fees: int}>
     */
    public function spentByProject(array $projects): array
    {
        if ([] === $projects) {
            return [];
        }

        /** @var list<array{eid: string|null, totalSec: string|int|null, feeSec: string|int|null}> $rows */
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(t.project) AS eid,
                    COALESCE(SUM(t.durationSeconds), 0) AS totalSec,
                    COALESCE(SUM(CASE WHEN t.billable = true THEN t.durationSeconds * COALESCE(t.rateAmount, 0) ELSE 0 END), 0) AS feeSec
             FROM ' . TimeEntry::class . ' t
             WHERE t.project IN (:projects) AND t.endedAt IS NOT NULL
             GROUP BY t.project',
        )->setParameter('projects', $projects)->getResult();

        $map = [];
        foreach ($rows as $row) {
            if (null === $row['eid']) {
                continue;
            }
            $map[$row['eid']] = [
                'seconds' => (int) $row['totalSec'],
                'fees' => (int) round(((int) $row['feeSec']) / 3600),
            ];
        }

        // Billable expenses consume a fees budget too (#666) — an project
        // that burns its budget on costs rather than hours still alerts.
        /** @var list<array{eid: string|null, amount: string|int|null}> $expenseRows */
        $expenseRows = $this->em->createQuery(
            'SELECT IDENTITY(x.project) AS eid,
                    COALESCE(SUM(x.amount), 0) AS amount
             FROM ' . Expense::class . ' x
             WHERE x.project IN (:projects) AND x.billable = true
             GROUP BY x.project',
        )->setParameter('projects', $projects)->getResult();

        foreach ($expenseRows as $row) {
            if (null === $row['eid']) {
                continue;
            }
            $entry = $map[$row['eid']] ?? ['seconds' => 0, 'fees' => 0];
            $entry['fees'] += (int) $row['amount'];
            $map[$row['eid']] = $entry;
        }

        return $map;
    }

    /**
     * Spent against THIS project's budget, in the budget's own unit
     * (minutes for hours, minor units for fees). Null when it has no budget.
     */
    public function spentFor(Project $project): ?int
    {
        if (null === $project->getBudgetType()) {
            return null;
        }
        $id = (string) $project->getId();
        $spent = $this->spentByProject([$project])[$id] ?? ['seconds' => 0, 'fees' => 0];

        return Project::BUDGET_HOURS === $project->getBudgetType()
            ? intdiv($spent['seconds'], 60)
            : $spent['fees'];
    }
}
