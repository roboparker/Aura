<?php

namespace App\Controller;

use App\Entity\Client;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\RetainerTransaction;
use App\Entity\User;
use App\Repository\RetainerTransactionRepository;
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
 * Client retainers (#673): pre-paid balances drawn down by invoices.
 *
 *  - deposits: an admin records money the client pre-paid;
 *  - the ledger view: balance + every movement;
 *  - apply-retainer: pays an invoice's balance due from the retainer —
 *    one InvoicePayment (method "retainer") + a matching draw, capped at
 *    min(retainer balance, balance due, requested amount).
 *
 * Removing a retainer payment (typo, wrong invoice) refunds the draw with
 * a reversing deposit — see InvoiceActionController::removePayment().
 * All routes ride the admin-reserved `invoices` category.
 */
class RetainerController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private RetainerTransactionRepository $retainers,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/clients/{id}/retainer', name: 'client_retainer_view', methods: ['GET'], priority: 10)]
    public function view(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $client = $this->authorizeClient($id, $user, SpacePermission::READ);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        $transactions = [];
        foreach ($this->retainers->findForClient($client) as $txn) {
            $transactions[] = [
                'id' => (string) $txn->getId(),
                'type' => $txn->getType(),
                'amount' => $txn->getAmount(),
                'note' => $txn->getNote(),
                'invoiceNumber' => $txn->getInvoice()?->getNumber(),
                'createdAt' => $txn->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'balance' => $this->retainers->balanceFor($client),
            'currency' => $client->getCurrency() ?? 'USD',
            'transactions' => $transactions,
        ]);
    }

    #[Route('/clients/{id}/retainer-deposits', name: 'client_retainer_deposit', methods: ['POST'], priority: 10)]
    public function deposit(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $client = $this->authorizeClient($id, $user, SpacePermission::UPDATE);
        if ($client instanceof JsonResponse) {
            return $client;
        }

        $payload = $request->toArray();
        $amount = $payload['amount'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            return $this->json(['error' => 'A positive amount (minor units) is required.'], 422);
        }
        $note = $payload['note'] ?? null;

        $txn = (new RetainerTransaction())
            ->setClient($client)
            ->setType(RetainerTransaction::TYPE_DEPOSIT)
            ->setAmount($amount)
            ->setNote(is_string($note) && '' !== trim($note) ? mb_substr(trim($note), 0, RetainerTransaction::MAX_NOTE_LENGTH) : null)
            ->setCreatedBy($user);
        $this->em->persist($txn);
        $this->em->flush();

        return $this->json(['balance' => $this->retainers->balanceFor($client)], 201);
    }

    /**
     * Pay (part of) an invoice from the client's retainer. Optional
     * `amount` caps the draw; default = as much as possible.
     */
    #[Route('/invoices/{id}/apply-retainer', name: 'invoice_apply_retainer', methods: ['POST'])]
    public function applyToInvoice(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }
        $invoice = $this->em->getRepository(Invoice::class)->find($id);
        $space = $invoice?->getSpace();
        $client = $invoice?->getClient();
        if (
            null === $invoice || null === $space || null === $client
            || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))
        ) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage invoices in this space.'], 403);
        }
        if (in_array($invoice->getStatus(), [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)) {
            return $this->json(['error' => 'Retainer can only be applied to an issued invoice.'], 409);
        }

        $balance = $this->retainers->balanceFor($client);
        $due = $invoice->getBalanceDue();
        $payload = $request->toArray();
        $requested = $payload['amount'] ?? null;
        if (null !== $requested && (!is_int($requested) || $requested <= 0)) {
            return $this->json(['error' => 'amount must be a positive integer (minor units).'], 422);
        }
        $amount = min($balance, $due, $requested ?? PHP_INT_MAX);
        if ($amount <= 0) {
            return $this->json(['error' => 'No retainer balance available (or nothing due).'], 409);
        }

        $payment = (new InvoicePayment())
            ->setAmount($amount)
            ->setPaidOn(new \DateTimeImmutable('today'))
            ->setMethod(InvoicePayment::METHOD_RETAINER)
            ->setNote('Applied from retainer')
            ->setCreatedBy($user);
        $invoice->addPayment($payment);

        $draw = (new RetainerTransaction())
            ->setClient($client)
            ->setType(RetainerTransaction::TYPE_DRAW)
            ->setAmount($amount)
            ->setInvoice($invoice)
            ->setNote(null !== $invoice->getNumber() ? 'Applied to ' . $invoice->getNumber() : 'Applied to invoice')
            ->setCreatedBy($user);
        $this->em->persist($draw);

        if (0 === $invoice->getBalanceDue()) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            $invoice->setPaidAt(new \DateTimeImmutable());
        }
        $this->em->flush();

        return $this->json([
            'applied' => $amount,
            'balanceDue' => $invoice->getBalanceDue(),
            'retainerBalance' => $this->retainers->balanceFor($client),
            'status' => $invoice->getStatus(),
        ], 201);
    }

    /**
     * Load the client and confirm the caller holds the given `invoices`
     * action, or return the short-circuit response.
     */
    private function authorizeClient(string $id, ?User $user, string $action): Client|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Client not found.'], 404);
        }
        $client = $this->em->getRepository(Client::class)->find($id);
        $space = $client?->getSpace();
        if (
            null === $client || null === $space
            || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))
        ) {
            return $this->json(['error' => 'Client not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, $action)
        ) {
            return $this->json(['error' => 'You cannot manage clients in this space.'], 403);
        }

        return $client;
    }
}
