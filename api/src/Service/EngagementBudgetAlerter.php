<?php

namespace App\Service;

use App\Entity\Engagement;
use App\Entity\Notification;
use App\Entity\Space;
use App\Repository\EngagementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The nightly budget alert sweep (#651): for every budgeted, non-archived
 * engagement, computes consumption and alerts the space's admins when a
 * threshold (80% / 100%) is crossed — once per threshold, stamped in the
 * engagement's budgetAlertsSent ledger so re-runs are idempotent. Editing the
 * budget clears the ledger, so a raised budget re-alerts when re-crossed.
 *
 * Alerts flow through NotificationDispatcher (#667): an in-app bell row plus
 * push + email under each admin's own preference matrix / quiet hours.
 */
final class EngagementBudgetAlerter
{
    public function __construct(
        private readonly EngagementRepository $engagements,
        private readonly EngagementBudgetCalculator $calculator,
        private readonly NotificationDispatcher $notifier,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Returns how many alerts were dispatched (one per admin per threshold). */
    public function dispatchDue(): int
    {
        $budgeted = $this->engagements->findBudgeted();
        if ([] === $budgeted) {
            return 0;
        }

        $spentMap = $this->calculator->spentByEngagement($budgeted);
        $sent = 0;
        $stamped = false;
        foreach ($budgeted as $engagement) {
            $budget = $engagement->getBudgetAmount();
            if (null === $budget || $budget <= 0) {
                continue;
            }
            $row = $spentMap[(string) $engagement->getId()] ?? ['seconds' => 0, 'fees' => 0];
            $spent = Engagement::BUDGET_HOURS === $engagement->getBudgetType()
                ? intdiv($row['seconds'], 60)
                : $row['fees'];
            $percent = (int) floor($spent / $budget * 100);

            foreach (Engagement::BUDGET_ALERT_THRESHOLDS as $threshold) {
                if ($percent < $threshold) {
                    continue;
                }
                if (in_array($threshold, $engagement->getBudgetAlertsSent(), true)) {
                    continue;
                }
                $sent += $this->alertAdmins($engagement, $threshold, $spent, $budget, $percent);
                $engagement->addBudgetAlertSent($threshold);
                $stamped = true;
            }
        }

        if ($stamped) {
            $this->em->flush();
        }
        if ($sent > 0) {
            $this->logger->info(sprintf('Sent %d engagement budget alert(s).', $sent));
        }

        return $sent;
    }

    private function alertAdmins(Engagement $engagement, int $threshold, int $spent, int $budget, int $percent): int
    {
        $space = $engagement->getSpace();
        if (null === $space) {
            return 0;
        }

        $body = Engagement::BUDGET_HOURS === $engagement->getBudgetType()
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
                title: sprintf('%s has used %d%% of its budget', $engagement->getName(), $percent),
                body: $body,
                targetPath: '/engagements',
            );
            if (null !== $notification) {
                ++$sent;
            }
        }

        return $sent;
    }
}
