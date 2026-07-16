<?php

namespace App\Controller;

use App\Entity\Space;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TimeEntryRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Billing reports (#647) — the Harvest-parity read side of time tracking:
 *
 *  - time-summary: period + group-by (client / engagement / category /
 *    person) with hours, billable hours, and billable amount per group;
 *  - uninvoiced: per-engagement unbilled billable hours + amount — the
 *    "what can I bill right now" view feeding the invoice generator (#644).
 *
 * Both accept `?format=csv` for a server-generated export with the same
 * filters. Amount math mirrors invoice generation exactly (hours rounded to
 * 2 decimals × the entry's snapshotted rate, falling back to the client
 * default) so a report never disagrees with the invoice it predicts.
 *
 * Access: the admin-reserved `invoices` category (space admins or explicit
 * grantees), space-scoped; outsiders get the usual existence-hiding 404.
 */
class ReportController extends AbstractController
{
    private const GROUP_BYS = ['client', 'engagement', 'category', 'person'];

    public function __construct(
        private EntityManagerInterface $em,
        private TimeEntryRepository $timeEntries,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/spaces/{id}/reports/time-summary', name: 'report_time_summary', methods: ['GET'])]
    public function timeSummary(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        $space = $this->authorize($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        $groupBy = $request->query->get('groupBy', 'engagement');
        if (!in_array($groupBy, self::GROUP_BYS, true)) {
            return $this->json(['error' => 'groupBy must be one of: ' . implode(', ', self::GROUP_BYS) . '.'], 422);
        }
        $from = $this->parseDate($request->query->get('from'));
        $to = $this->parseDate($request->query->get('to'));
        if (false === $from || false === $to) {
            return $this->json(['error' => 'Dates must be formatted YYYY-MM-DD.'], 422);
        }
        if (null !== $from && null !== $to && $to < $from) {
            return $this->json(['error' => 'The end of the range is before its start.'], 422);
        }

        $entries = $this->timeEntries->findCompletedForSpace($space, $from, $to?->modify('+1 day'));

        /** @var array<string, array{label: string, seconds: int, billableSeconds: int, amounts: array<string, int>, costs: array<string, int>}> $rows */
        $rows = [];
        foreach ($entries as $entry) {
            [$key, $label] = $this->groupKey($entry, $groupBy);
            $row = $rows[$key] ?? ['label' => $label, 'seconds' => 0, 'billableSeconds' => 0, 'amounts' => [], 'costs' => []];

            $seconds = $entry->getDurationSeconds() ?? 0;
            $row['seconds'] += $seconds;
            if ($entry->isBillable()) {
                $row['billableSeconds'] += $seconds;
                $amount = $this->entryAmount($entry, $seconds);
                if ($amount > 0) {
                    $currency = $this->entryCurrency($entry);
                    $row['amounts'][$currency] = ($row['amounts'][$currency] ?? 0) + $amount;
                }
            }
            // Cost (#653) accrues on ALL tracked time — non-billable hours
            // still cost the team — using the entry's snapshotted cost rate.
            $costRate = $entry->getCostRateAmount();
            if (null !== $costRate && $costRate > 0) {
                $cost = (int) round(round($seconds / 3600, 2) * $costRate);
                if ($cost > 0) {
                    $currency = $this->entryCurrency($entry);
                    $row['costs'][$currency] = ($row['costs'][$currency] ?? 0) + $cost;
                }
            }
            $rows[$key] = $row;
        }

        uasort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $totals = ['seconds' => 0, 'billableSeconds' => 0, 'amounts' => [], 'costs' => []];
        $list = [];
        foreach ($rows as $key => $row) {
            $totals['seconds'] += $row['seconds'];
            $totals['billableSeconds'] += $row['billableSeconds'];
            foreach ($row['amounts'] as $currency => $amount) {
                $totals['amounts'][$currency] = ($totals['amounts'][$currency] ?? 0) + $amount;
            }
            foreach ($row['costs'] as $currency => $cost) {
                $totals['costs'][$currency] = ($totals['costs'][$currency] ?? 0) + $cost;
            }
            $list[] = [
                'key' => $key,
                'label' => $row['label'],
                'seconds' => $row['seconds'],
                'hours' => round($row['seconds'] / 3600, 2),
                'billableSeconds' => $row['billableSeconds'],
                'billableHours' => round($row['billableSeconds'] / 3600, 2),
                'amounts' => $row['amounts'],
                'costs' => $row['costs'],
                'profit' => $this->profitOf($row['amounts'], $row['costs']),
            ];
        }

        if ('csv' === $request->query->get('format')) {
            $csvRows = [];
            foreach ($list as $row) {
                $csvRows[] = [
                    $row['label'],
                    number_format($row['hours'], 2, '.', ''),
                    number_format($row['billableHours'], 2, '.', ''),
                    $this->amountsLabel($row['amounts']),
                    $this->amountsLabel($row['costs']),
                    $this->amountsLabel($row['profit']),
                ];
            }

            return $this->csv(
                sprintf('time-summary-by-%s.csv', $groupBy),
                [ucfirst($groupBy), 'Hours', 'Billable hours', 'Billable amount', 'Cost', 'Profit'],
                $csvRows,
            );
        }

        return $this->json([
            'groupBy' => $groupBy,
            'from' => $from?->format('Y-m-d'),
            'to' => $to?->format('Y-m-d'),
            'rows' => $list,
            'totals' => [
                'seconds' => $totals['seconds'],
                'hours' => round($totals['seconds'] / 3600, 2),
                'billableSeconds' => $totals['billableSeconds'],
                'billableHours' => round($totals['billableSeconds'] / 3600, 2),
                'amounts' => $totals['amounts'],
                'costs' => $totals['costs'],
                'profit' => $this->profitOf($totals['amounts'], $totals['costs']),
            ],
        ]);
    }

    /**
     * Billable amount − cost, per currency (#653). Currencies that only
     * appear on one side still surface (cost-only → negative profit).
     *
     * @param array<string, int> $amounts
     * @param array<string, int> $costs
     *
     * @return array<string, int>
     */
    private function profitOf(array $amounts, array $costs): array
    {
        $profit = $amounts;
        foreach ($costs as $currency => $cost) {
            $profit[$currency] = ($profit[$currency] ?? 0) - $cost;
        }

        return $profit;
    }

    #[Route('/spaces/{id}/reports/uninvoiced', name: 'report_uninvoiced', methods: ['GET'])]
    public function uninvoiced(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        $space = $this->authorize($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        /** @var array<string, array{engagement: string, client: string|null, currency: string, entryCount: int, seconds: int, amount: int}> $rows */
        $rows = [];
        foreach ($this->timeEntries->findUninvoicedForSpace($space) as $entry) {
            $engagement = $entry->getEngagement();
            if (null === $engagement) {
                continue;
            }
            $key = (string) $engagement->getId();
            $row = $rows[$key] ?? [
                'engagement' => $engagement->getName(),
                'client' => $engagement->getClient()?->getName(),
                'currency' => $this->entryCurrency($entry),
                'entryCount' => 0,
                'seconds' => 0,
                'amount' => 0,
            ];
            $seconds = $entry->getDurationSeconds() ?? 0;
            ++$row['entryCount'];
            $row['seconds'] += $seconds;
            $row['amount'] += $this->entryAmount($entry, $seconds);
            $rows[$key] = $row;
        }

        uasort($rows, static fn (array $a, array $b): int => strcasecmp($a['engagement'], $b['engagement']));

        $list = [];
        foreach ($rows as $key => $row) {
            $list[] = [
                'engagementId' => $key,
                'engagement' => $row['engagement'],
                'client' => $row['client'],
                'entryCount' => $row['entryCount'],
                'seconds' => $row['seconds'],
                'hours' => round($row['seconds'] / 3600, 2),
                'amount' => $row['amount'],
                'currency' => $row['currency'],
            ];
        }

        if ('csv' === $request->query->get('format')) {
            $csvRows = [];
            foreach ($list as $row) {
                $csvRows[] = [
                    $row['engagement'],
                    $row['client'] ?? '',
                    (string) $row['entryCount'],
                    number_format($row['hours'], 2, '.', ''),
                    number_format($row['amount'] / 100, 2, '.', '') . ' ' . $row['currency'],
                ];
            }

            return $this->csv(
                'uninvoiced.csv',
                ['Engagement', 'Client', 'Entries', 'Hours', 'Amount'],
                $csvRows,
            );
        }

        return $this->json(['rows' => $list]);
    }

    /** Same per-entry math as invoice generation: round(hours, 2) × rate. */
    private function entryAmount(TimeEntry $entry, int $seconds): int
    {
        $rate = $entry->getRateAmount()
            ?? $entry->getEngagement()?->getClient()?->getDefaultRateAmount()
            ?? 0;

        return (int) round(round($seconds / 3600, 2) * $rate);
    }

    private function entryCurrency(TimeEntry $entry): string
    {
        $engagement = $entry->getEngagement();

        return $entry->getRateCurrency()
            ?? $engagement?->getCurrency()
            ?? $engagement?->getClient()?->getCurrency()
            ?? 'USD';
    }

    /** @return array{string, string} [stable key, display label] */
    private function groupKey(TimeEntry $entry, string $groupBy): array
    {
        switch ($groupBy) {
            case 'client':
                $client = $entry->getEngagement()?->getClient();

                return null === $client
                    ? ['none', 'No client']
                    : ['client:' . $client->getId(), $client->getName()];
            case 'category':
                $category = $entry->getCategory();

                return null === $category
                    ? ['none', 'No category']
                    : ['category:' . $category->getId(), $category->getName()];
            case 'person':
                $person = $entry->getUser();
                if (null === $person) {
                    return ['none', 'Unknown'];
                }
                $name = trim($person->getGivenName() . ' ' . $person->getFamilyName());

                return ['person:' . $person->getId(), '' !== $name ? $name : $person->getEmail()];
            default:
                $engagement = $entry->getEngagement();

                return null === $engagement
                    ? ['none', 'No engagement']
                    : ['engagement:' . $engagement->getId(), $engagement->getName()];
        }
    }

    /**
     * "12.34 USD" / "12.34 USD; 5.00 EUR" for the CSV amount cell.
     *
     * @param array<string, int> $amounts
     */
    private function amountsLabel(array $amounts): string
    {
        $parts = [];
        foreach ($amounts as $currency => $amount) {
            $parts[] = number_format($amount / 100, 2, '.', '') . ' ' . $currency;
        }

        return implode('; ', $parts);
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function csv(string $filename, array $headers, array $rows): Response
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            return $this->json(['error' => 'Could not build the export.'], 500);
        }
        fputcsv($handle, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response(false === $csv ? '' : $csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    /** A `Y-m-d` query date → midnight UTC; null when absent, false when malformed. */
    private function parseDate(?string $value): \DateTimeImmutable|false|null
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
    }

    /**
     * Load the space and confirm the caller may read billing reports, or
     * return the error response to short-circuit with.
     */
    private function authorize(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::READ)
        ) {
            return $this->json(['error' => 'You cannot view billing reports in this space.'], 403);
        }

        return $space;
    }
}
