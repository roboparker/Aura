<?php

namespace App\Controller;

use App\Entity\Engagement;
use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TimeEntryRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Generates a draft {@see Invoice} from a {@see Engagement}'s unbilled,
 * billable, completed {@see TimeEntry} rows (Harvest model). The client + currency
 * come from the engagement; one line item per entry (hours × the entry's
 * snapshotted category rate), each back-linking its source entry, and every
 * pulled entry stamped `billedAt` so the same time can't be billed twice.
 *
 * Body: `{ engagement }` (IRI or UUID), plus optional scoping (#644):
 *  - `from` / `to` — inclusive `Y-m-d` dates on the entry's startedAt, so
 *    "invoice just October" is possible;
 *  - `entries` — an explicit list of entry IRIs/UUIDs to pull; every selected
 *    entry must be in the invoiceable pool (unbilled, billable, completed, on
 *    this engagement, inside the range) or the whole request 422s;
 *  - `preview: true` — return the candidate rows + totals WITHOUT creating an
 *    invoice or stamping billedAt, so the UI can offer per-entry unticks.
 *
 * Auth: creating invoices is admin-reserved — the caller must be a space admin
 * or hold an explicit invoicing role.
 */
class InvoiceFromTimeController extends AbstractController
{
    private const SECONDS_PER_HOUR = 3600.0;

    public function __construct(
        private EntityManagerInterface $em,
        private TimeEntryRepository $timeEntries,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/invoices/from-time-entries', name: 'invoice_from_time', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $payload = $request->toArray();
        $engagement = $this->resolve($payload['engagement'] ?? null);
        $space = $engagement?->getSpace();
        if (null === $engagement || null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Engagement not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::CREATE)
        ) {
            return $this->json(['error' => 'You cannot create invoices in this space.'], 403);
        }

        $client = $engagement->getClient();
        if (null === $client) {
            return $this->json(['error' => 'This engagement has no client.'], 422);
        }

        $from = $this->parseDate($payload['from'] ?? null);
        $to = $this->parseDate($payload['to'] ?? null);
        if (false === $from || false === $to) {
            return $this->json(['error' => 'Dates must be formatted YYYY-MM-DD.'], 422);
        }
        if (null !== $from && null !== $to && $to < $from) {
            return $this->json(['error' => 'The end of the range is before its start.'], 422);
        }

        $entries = $this->timeEntries->findInvoiceableForEngagement(
            $engagement,
            $from,
            $to?->modify('+1 day'),
        );

        // Optional explicit selection — must be a subset of the pool, so a
        // stale/foreign/billed reference fails loudly instead of silently
        // shrinking the invoice.
        $selection = $payload['entries'] ?? null;
        if (is_array($selection)) {
            $wanted = [];
            foreach ($selection as $ref) {
                $id = $this->entryId($ref);
                if (null === $id) {
                    return $this->json(['error' => 'Invalid entry reference in the selection.'], 422);
                }
                $wanted[$id] = true;
            }
            $entries = array_values(array_filter(
                $entries,
                static fn (TimeEntry $t): bool => isset($wanted[(string) $t->getId()]),
            ));
            if (count($entries) !== count($wanted)) {
                return $this->json([
                    'error' => 'One or more selected entries are not invoiceable for this engagement.',
                ], 422);
            }
        }

        $currency = $engagement->getCurrency() ?? $client->getCurrency() ?? 'USD';

        if (true === ($payload['preview'] ?? false)) {
            return $this->preview($entries, $client->getDefaultRateAmount(), $currency);
        }

        if (0 === count($entries)) {
            return $this->json(['error' => 'No unbilled billable time to invoice.'], 422);
        }

        $now = new \DateTimeImmutable();
        $invoice = (new Invoice())
            ->setSpace($space)
            ->setClient($client)
            ->setCurrency($currency)
            ->setCreatedBy($user);

        $subtotal = 0;
        $position = 0;
        foreach ($entries as $entry) {
            $hours = round(($entry->getDurationSeconds() ?? 0) / self::SECONDS_PER_HOUR, 2);
            $unitAmount = $entry->getRateAmount() ?? $client->getDefaultRateAmount() ?? 0;
            $amount = (int) round($hours * $unitAmount);
            $subtotal += $amount;

            $line = (new InvoiceLineItem())
                ->setDescription($this->lineLabel($entry))
                ->setQuantity($hours)
                ->setUnitAmount($unitAmount)
                ->setAmount($amount)
                ->setPosition($position++)
                ->setSourceTimeEntry($entry);
            $invoice->addLineItem($line);

            $entry->setBilledAt($now);
        }

        $invoice->setSubtotal($subtotal);
        $invoice->setTaxAmount(0);
        $invoice->setTotal($subtotal);

        $this->em->persist($invoice);
        $this->em->flush();

        return $this->json([
            '@id' => '/invoices/' . $invoice->getId(),
            'id' => (string) $invoice->getId(),
            'status' => $invoice->getStatus(),
            'currency' => $invoice->getCurrency(),
            'subtotal' => $invoice->getSubtotal(),
            'total' => $invoice->getTotal(),
            'lineItemCount' => count($entries),
        ], 201);
    }

    /**
     * The candidate rows + totals for the UI's untick list — same math as the
     * generation loop, but read-only: nothing is created, nothing is stamped.
     *
     * @param list<TimeEntry> $entries
     */
    private function preview(array $entries, ?int $clientDefaultRate, string $currency): JsonResponse
    {
        $rows = [];
        $subtotal = 0;
        foreach ($entries as $entry) {
            $hours = round(($entry->getDurationSeconds() ?? 0) / self::SECONDS_PER_HOUR, 2);
            $unitAmount = $entry->getRateAmount() ?? $clientDefaultRate ?? 0;
            $amount = (int) round($hours * $unitAmount);
            $subtotal += $amount;

            $rows[] = [
                '@id' => '/time_entries/' . $entry->getId(),
                'id' => (string) $entry->getId(),
                'description' => $entry->getDescription(),
                'categoryName' => $entry->getCategory()?->getName(),
                'startedAt' => $entry->getStartedAt()?->format(\DateTimeInterface::ATOM),
                'durationSeconds' => $entry->getDurationSeconds(),
                'hours' => $hours,
                'unitAmount' => $unitAmount,
                'amount' => $amount,
            ];
        }

        return $this->json([
            'entries' => $rows,
            'count' => count($rows),
            'subtotal' => $subtotal,
            'currency' => $currency,
        ]);
    }

    /** A `Y-m-d` payload date → midnight UTC; null when absent, false when malformed. */
    private function parseDate(mixed $value): \DateTimeImmutable|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            return false;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new \DateTimeZone('UTC'));
    }

    /** Normalise an entry reference (IRI or bare UUID) to a UUID string, or null. */
    private function entryId(mixed $ref): ?string
    {
        if (!is_string($ref) || '' === trim($ref)) {
            return null;
        }
        $trimmed = trim($ref);
        $id = Uuid::isValid($trimmed) ? $trimmed : substr($trimmed, (int) strrpos($trimmed, '/') + 1);

        return Uuid::isValid($id) ? $id : null;
    }

    /** "Category — description" (either part optional), capped to the column length. */
    private function lineLabel(TimeEntry $entry): string
    {
        $parts = [];
        $category = $entry->getCategory()?->getName();
        if (null !== $category && '' !== trim($category)) {
            $parts[] = trim($category);
        }
        $description = $entry->getDescription();
        if (null !== $description && '' !== trim($description)) {
            $parts[] = trim($description);
        }
        $label = [] === $parts ? 'Tracked time' : implode(' — ', $parts);

        return mb_substr($label, 0, InvoiceLineItem::MAX_DESCRIPTION_LENGTH);
    }

    private function resolve(mixed $iri): ?Engagement
    {
        if (!is_string($iri) || '' === trim($iri)) {
            return null;
        }
        $trimmed = trim($iri);
        $id = Uuid::isValid($trimmed) ? $trimmed : substr($trimmed, (int) strrpos($trimmed, '/') + 1);
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(Engagement::class)->find($id);
    }
}
