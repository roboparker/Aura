<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Growth\GrowthMetrics;
use App\Service\Mail\MailDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

/**
 * The weekly growth digest (#763): last week's funnel, emailed to every admin.
 *
 * A dashboard only helps if someone opens it. The launch plan's 30-day review
 * has hard targets, and a number that only exists behind a login is a number
 * nobody watches — so the summary comes to you.
 */
final class GrowthDigestMailer
{
    public function __construct(
        private readonly MailDispatcher $mail,
        private readonly GrowthMetrics $metrics,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Builds last week's numbers and sends them to every admin.
     *
     * @return int how many admins were emailed
     */
    public function sendWeekly(?\DateTimeImmutable $now = null): int
    {
        $now ??= new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        // `to` is exclusive throughout GrowthMetrics.
        $to = $now->modify('+1 day');
        $from = $to->modify('-7 days');
        $priorFrom = $from->modify('-7 days');

        $funnel = $this->metrics->funnel($from, $to, 'week');
        $prior = $this->metrics->funnel($priorFrom, $from, 'week');

        $totals = [];
        $deltas = [];
        foreach (['signups', 'activations', 'upgrades', 'churn'] as $stage) {
            $totals[$stage] = $this->sum($funnel[$stage] ?? []);
            $deltas[$stage] = $totals[$stage] - $this->sum($prior[$stage] ?? []);
        }

        $admins = $this->admins();
        foreach ($admins as $admin) {
            $email = (new TemplatedEmail())
                ->to($admin->getEmail())
                ->subject(sprintf(
                    'Madori weekly: %d signups, %d upgrades',
                    $totals['signups'],
                    $totals['upgrades'],
                ))
                ->htmlTemplate('emails/growth_digest.html.twig')
                ->textTemplate('emails/growth_digest.txt.twig')
                ->context([
                    'recipient' => $admin,
                    'from' => $from,
                    'to' => $to->modify('-1 day'),
                    'totals' => $totals,
                    'deltas' => $deltas,
                    'snapshot' => $this->metrics->snapshot(),
                    'dashboardUrl' => $this->mail->frontendLink('/admin/growth'),
                ]);

            $this->mail->send($email);
        }

        return count($admins);
    }

    /**
     * "Someone just paid you" — sent to every admin the moment a subscription
     * first reaches a paying status.
     *
     * The one alert worth interrupting for on a bootstrap launch. Fired from
     * the Stripe webhook, which is the only place that knows the transition
     * actually happened rather than being a re-delivered event.
     */
    public function sendPaidSignupAlert(Subscription $subscription): void
    {
        // Every subscription is owned by an organization now. For a personal
        // one, name the person rather than their account's display name —
        // "alex@example.com upgraded" is more useful in an alert than "Alex".
        $organization = $subscription->getOrganization();
        if (null === $organization) {
            $who = 'an account';
        } elseif ($organization->getIsPersonal()) {
            $who = $organization->getCreatedBy()?->getEmail() ?? 'an account';
        } else {
            $who = $organization->getName();
        }

        foreach ($this->admins() as $admin) {
            $email = (new TemplatedEmail())
                ->to($admin->getEmail())
                ->subject(sprintf('New %s subscription', ucfirst($subscription->getPlan())))
                ->htmlTemplate('emails/paid_signup.html.twig')
                ->textTemplate('emails/paid_signup.txt.twig')
                ->context([
                    'recipient' => $admin,
                    'who' => $who,
                    'plan' => ucfirst($subscription->getPlan()),
                    'seats' => $subscription->getSeats(),
                    'interval' => $subscription->getBillingInterval() ?? 'month',
                    'status' => $subscription->getStatus(),
                    'dashboardUrl' => $this->mail->frontendLink('/admin/growth'),
                ]);

            $this->mail->send($email);
        }
    }

    /**
     * Admins to notify.
     *
     * Two steps on purpose. `roles` is a JSON column, so it can't be matched
     * with DQL's LIKE — a raw `::text` prefilter narrows the table to a handful
     * of candidates. Those are then confirmed through `User::getRoles()` rather
     * than trusting the stored JSON, because that method is where the waitlist
     * override lives: a waitlisted account holds only ROLE_WAITLISTED whatever
     * its stored roles say, and shouldn't be emailed the company's numbers.
     *
     * @return list<User>
     */
    private function admins(): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM "user" WHERE roles::text LIKE :role AND deactivated_at IS NULL',
            ['role' => '%ROLE_ADMIN%'],
        );
        if ([] === $ids) {
            return [];
        }

        /** @var list<User> $candidates */
        $candidates = $this->em->getRepository(User::class)->findBy(['id' => $ids]);

        return array_values(array_filter(
            $candidates,
            static fn (User $user): bool => in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }

    /** @param list<array{period: string, value: int}> $points */
    private function sum(array $points): int
    {
        return array_sum(array_column($points, 'value'));
    }
}
