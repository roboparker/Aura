<?php

namespace App\Controller;

use App\Entity\BillingProject;
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
 * Generates a draft {@see Invoice} from a {@see BillingProject}'s unbilled,
 * billable, completed {@see TimeEntry} rows (Harvest model). The client + currency
 * come from the billing project; one line item per entry (hours × the entry's
 * snapshotted category rate), each back-linking its source entry, and every
 * pulled entry stamped `billedAt` so the same time can't be billed twice.
 *
 * Body: `{ billingProject }` (IRI or UUID). Auth: creating invoices is
 * admin-reserved — the caller must be a space admin or hold an explicit
 * invoicing role.
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
        $billingProject = $this->resolve($payload['billingProject'] ?? null);
        $space = $billingProject?->getSpace();
        if (null === $billingProject || null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Billing project not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::CREATE)
        ) {
            return $this->json(['error' => 'You cannot create invoices in this space.'], 403);
        }

        $client = $billingProject->getClient();
        if (null === $client) {
            return $this->json(['error' => 'This billing project has no client.'], 422);
        }

        $entries = $this->timeEntries->findInvoiceableForBillingProject($billingProject);
        if (0 === count($entries)) {
            return $this->json(['error' => 'No unbilled billable time to invoice.'], 422);
        }

        $now = new \DateTimeImmutable();
        $currency = $billingProject->getCurrency() ?? $client->getCurrency() ?? 'USD';
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

    private function resolve(mixed $iri): ?BillingProject
    {
        if (!is_string($iri) || '' === trim($iri)) {
            return null;
        }
        $trimmed = trim($iri);
        $id = Uuid::isValid($trimmed) ? $trimmed : substr($trimmed, (int) strrpos($trimmed, '/') + 1);
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository(BillingProject::class)->find($id);
    }
}
