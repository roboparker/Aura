<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\Notification;
use App\Entity\Space;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The nightly budget alert sweep (#651): for every budgeted, non-archived
 * project, computes consumption and alerts the space's admins when a
 * threshold (80% / 100%) is crossed — once per threshold, stamped in the
 * project's budgetAlertsSent ledger so re-runs are idempotent. Editing the
 * budget clears the ledger, so a raised budget re-alerts when re-crossed.
 *
 * Alerts flow through NotificationDispatcher (#667): an in-app bell row plus
 * push + email under each admin's own preference matrix / quiet hours.
 */
final class ProjectBudgetAlerter
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectBudgetCalculator $calculator,
        private readonly NotificationDispatcher $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Returns how many alerts were dispatched (one per admin per threshold). */
    public function dispatchDue(): int
    {
        $budgeted = $this->projects->findBudgeted();
        if ([] === $budgeted) {
            return 0;
        }

        $spentMap = $this->calculator->spentByProject($budgeted);
        $sent = 0;
        $stamped = false;
        foreach ($budgeted as $project) {
            $budget = $project->getBudgetAmount();
            if (null === $budget || $budget <= 0) {
                continue;
            }
            $row = $spentMap[(string) $project->getId()] ?? ['seconds' => 0, 'fees' => 0];
            $spent = Project::BUDGET_HOURS === $project->getBudgetType()
                ? intdiv($row['seconds'], 60)
                : $row['fees'];
            $percent = (int) floor($spent / $budget * 100);

            foreach (Project::BUDGET_ALERT_THRESHOLDS as $threshold) {
                if ($percent < $threshold) {
                    continue;
                }
                if (in_array($threshold, $project->getBudgetAlertsSent(), true)) {
                    continue;
                }
                $sent += $this->alertAdmins($project, $threshold, $spent, $budget, $percent);
                $project->addBudgetAlertSent($threshold);
                $stamped = true;
            }
        }

        if ($stamped) {
            $this->em->flush();
        }
        if ($sent > 0) {
            $this->logger->info(sprintf('Sent %d project budget alert(s).', $sent));
        }

        return $sent;
    }

    private function alertAdmins(Project $project, int $threshold, int $spent, int $budget, int $percent): int
    {
        $space = $project->getSpace();
        if (null === $space) {
            return 0;
        }

        $body = Project::BUDGET_HOURS === $project->getBudgetType()
            ? sprintf(
                '%s of the %s-hour budget is used (%d%%, %d%% threshold).',
                number_format($spent / 60, 1),
                number_format($budget / 60, 1),
                $percent,
                $threshold,
            )
            : sprintf(
                '%s of the %s budget is used (%d%%, %d%% threshold).',
                number_format($spent / 100, 2),
                number_format($budget / 100, 2),
                $percent,
                $threshold,
            );

        $sent = 0;
        foreach ($space->getUserMemberships() as $membership) {
            if (Space::ROLE_ADMIN !== $membership->getRole()) {
                continue;
            }
            $admin = $membership->getUser();
            if (null === $admin) {
                continue;
            }

            $notification = $this->notifier->notify(
                recipient: $admin,
                type: Notification::TYPE_BUDGET_ALERT,
                actor: null,
                title: sprintf('%s has used %d%% of its budget', $project->getName(), $percent),
                body: $body,
                targetPath: '/projects',
            );
            if (null !== $notification) {
                ++$sent;
            }
        }

        return $sent;
    }
}
