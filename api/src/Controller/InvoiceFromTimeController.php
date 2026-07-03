<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\Project;
use App\Entity\Space;
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
 * Generates a draft {@see Invoice} from a space's unbilled, billable, completed
 * {@see \App\Entity\TimeEntry} rows (#444 + #445 — the paired heart of the two
 * features). One line item per entry: hours × the entry's rate (falling back to
 * the client's default), each line back-linking its source entry, and every
 * pulled entry stamped `billedAt` so the same time can't be billed twice.
 *
 * Body: `{ space, client, project? }` (IRIs). Optionally narrowed to one
 * project. Auth: creating invoices is admin-reserved — the caller must be a
 * space admin or hold an explicit invoicing role (mirrors the entity gate).
 *
 * v1 assumes a single currency (the client's); rate-currency mismatches on
 * individual entries are not converted.
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

        $space = $this->resolve($payload['space'] ?? null, Space::class);
        if (!$space instanceof Space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::CREATE)
        ) {
            return $this->json(['error' => 'You cannot create invoices in this space.'], 403);
        }

        $client = $this->resolve($payload['client'] ?? null, Client::class);
        if (
            !$client instanceof Client
            || true !== $client->getSpace()?->getId()?->equals($space->getId())
        ) {
            return $this->json(['error' => 'Client not found in this space.'], 404);
        }

        $project = null;
        if (isset($payload['project']) && is_string($payload['project']) && '' !== trim($payload['project'])) {
            $resolved = $this->resolve($payload['project'], Project::class);
            if (
                !$resolved instanceof Project
                || true !== $resolved->getSpace()?->getId()?->equals($space->getId())
            ) {
                return $this->json(['error' => 'Project not found in this space.'], 404);
            }
            $project = $resolved;
        }

        $entries = $this->timeEntries->findInvoiceable($space, $project);
        if (0 === count($entries)) {
            return $this->json(['error' => 'No unbilled billable time to invoice.'], 422);
        }

        $now = new \DateTimeImmutable();
        $currency = $client->getCurrency() ?? 'USD';
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
                ->setDescription($this->lineLabel($entry->getDescription(), $entry->getProject()))
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

    private function lineLabel(?string $description, ?Project $project): string
    {
        $label = null !== $description ? trim($description) : '';
        if ('' !== $label) {
            return mb_substr($label, 0, InvoiceLineItem::MAX_DESCRIPTION_LENGTH);
        }
        if (null !== $project) {
            return mb_substr('Time — ' . $project->getTitle(), 0, InvoiceLineItem::MAX_DESCRIPTION_LENGTH);
        }

        return 'Tracked time';
    }

    /**
     * @param class-string $class
     */
    private function resolve(mixed $iri, string $class): ?object
    {
        if (!is_string($iri) || '' === trim($iri)) {
            return null;
        }
        $trimmed = trim($iri);
        $id = Uuid::isValid($trimmed)
            ? $trimmed
            : substr($trimmed, (int) strrpos($trimmed, '/') + 1);
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->em->getRepository($class)->find($id);
    }
}
