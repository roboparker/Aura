<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Service\InvoiceMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Invoice lifecycle transitions (#445) that are more than a field edit:
 *
 *  - issue: assign the next per-space sequential number + issue date and move
 *    draft → sent (a draft burns no number, so numbers stay gap-free).
 *  - mark-paid: record payment manually (bank transfer / cash) — the offline
 *    path that always exists alongside future online payment.
 *  - void: cancel an invoice and release its billed time entries so the hours
 *    can be re-invoiced.
 *
 * All are admin-reserved (invoices.update) and hide a non-member's target
 * behind a 404, like the rest of the access model.
 */
class InvoiceActionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private InvoiceRepository $invoices,
        private SpacePermissionResolver $permissions,
        private InvoiceMailer $mailer,
        private \App\Service\InvoiceNumberer $numberer,
    ) {
    }

    #[Route('/invoices/{id}/issue', name: 'invoice_issue', methods: ['POST'])]
    public function issue(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }

        if (Invoice::STATUS_DRAFT !== $invoice->getStatus()) {
            return $this->json(['error' => 'Only a draft invoice can be issued.'], 409);
        }

        $this->numberer->assign($invoice);
        if (null === $invoice->getIssueDate()) {
            $invoice->setIssueDate(new \DateTimeImmutable('today'));
        }
        $invoice->setStatus(Invoice::STATUS_SENT);
        $this->em->flush();

        return $this->summary($invoice);
    }

    #[Route('/invoices/{id}/mark-paid', name: 'invoice_mark_paid', methods: ['POST'])]
    public function markPaid(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }

        if (Invoice::STATUS_VOID === $invoice->getStatus()) {
            return $this->json(['error' => 'A void invoice cannot be marked paid.'], 409);
        }

        // Record the remaining balance as a payment (#648) so the ledger and
        // the status agree — a fully-paid-by-parts invoice records nothing new.
        $balance = $invoice->getBalanceDue();
        if ($balance > 0) {
            $invoice->addPayment(
                (new InvoicePayment())
                    ->setAmount($balance)
                    ->setMethod(InvoicePayment::METHOD_MANUAL)
                    ->setCreatedBy($user instanceof User ? $user : null),
            );
        }
        $invoice->setStatus(Invoice::STATUS_PAID);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->summary($invoice);
    }

    /**
     * Record a (possibly partial) payment (#648). Balance due = total − Σ
     * payments; the status flips to paid only when the balance hits zero and
     * stays sent/overdue while anything is outstanding.
     */
    #[Route('/invoices/{id}/payments', name: 'invoice_payment_add', methods: ['POST'])]
    public function addPayment(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }
        if (in_array($invoice->getStatus(), [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)) {
            return $this->json(['error' => 'Payments can only be recorded on an issued invoice.'], 409);
        }

        $payload = $request->toArray();
        $amount = $payload['amount'] ?? null;
        if (!is_int($amount) || $amount <= 0) {
            return $this->json(['error' => 'A positive amount (minor units) is required.'], 422);
        }
        if ($amount > $invoice->getBalanceDue()) {
            return $this->json(['error' => 'The payment exceeds the balance due.'], 422);
        }

        $paidOn = new \DateTimeImmutable('today');
        $paidOnRaw = $payload['paidOn'] ?? null;
        if (is_string($paidOnRaw) && '' !== trim($paidOnRaw)) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($paidOnRaw), new \DateTimeZone('UTC'));
            if (false === $parsed) {
                return $this->json(['error' => 'paidOn must be formatted YYYY-MM-DD.'], 422);
            }
            $paidOn = $parsed;
        }

        $method = $payload['method'] ?? null;
        $note = $payload['note'] ?? null;
        $payment = (new InvoicePayment())
            ->setAmount($amount)
            ->setPaidOn($paidOn)
            ->setMethod(is_string($method) && '' !== trim($method) ? mb_substr(trim($method), 0, 40) : InvoicePayment::METHOD_MANUAL)
            ->setNote(is_string($note) && '' !== trim($note) ? mb_substr(trim($note), 0, 255) : null)
            ->setCreatedBy($user instanceof User ? $user : null);
        $invoice->addPayment($payment);

        if (0 === $invoice->getBalanceDue()) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            $invoice->setPaidAt(new \DateTimeImmutable());
        }
        $this->em->flush();

        return $this->paymentSummary($invoice);
    }

    /**
     * Remove a recorded payment (a typo'd amount, a bounced check). If the
     * invoice was paid and the balance reopens, the status reverts to
     * sent/overdue (past-due picks overdue).
     */
    #[Route('/invoices/{id}/payments/{paymentId}', name: 'invoice_payment_remove', methods: ['DELETE'])]
    public function removePayment(string $id, string $paymentId, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }

        if (!Uuid::isValid($paymentId)) {
            return $this->json(['error' => 'Payment not found.'], 404);
        }
        $payment = null;
        foreach ($invoice->getPayments() as $candidate) {
            if ($candidate->getId()?->toRfc4122() === strtolower($paymentId)) {
                $payment = $candidate;
                break;
            }
        }
        if (null === $payment) {
            return $this->json(['error' => 'Payment not found.'], 404);
        }

        $invoice->removePayment($payment);

        if (Invoice::STATUS_PAID === $invoice->getStatus() && $invoice->getBalanceDue() > 0) {
            $pastDue = null !== $invoice->getDueDate() && $invoice->getDueDate() < new \DateTimeImmutable('today');
            $invoice->setStatus($pastDue ? Invoice::STATUS_OVERDUE : Invoice::STATUS_SENT);
            $invoice->setPaidAt(null);
        }
        $this->em->flush();

        return $this->paymentSummary($invoice);
    }

    #[Route('/invoices/{id}/send', name: 'invoice_send', methods: ['POST'])]
    public function send(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }
        if (Invoice::STATUS_VOID === $invoice->getStatus()) {
            return $this->json(['error' => 'A void invoice cannot be sent.'], 409);
        }

        // Assign a number on first send (like issue), so a sent invoice is final.
        $this->numberer->assign($invoice);
        if (null === $invoice->getIssueDate()) {
            $invoice->setIssueDate(new \DateTimeImmutable('today'));
        }

        // Mint a shareable public token (sha256-hashed at rest, like the export
        // + password-reset tokens); the plaintext only leaves in the link.
        $plain = bin2hex(random_bytes(32));
        $invoice->setPublicToken(hash('sha256', $plain));
        if (Invoice::STATUS_DRAFT === $invoice->getStatus()) {
            $invoice->setStatus(Invoice::STATUS_SENT);
        }
        $invoice->setSentAt(new \DateTimeImmutable());
        $this->em->flush();

        // Best-effort: email the client the public link (no-op without an email).
        $this->mailer->sendInvoice($invoice, $plain);

        return $this->json([
            'id' => (string) $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'token' => $plain,
            'publicUrl' => '/public/invoices/' . $plain,
        ], 201);
    }

    #[Route('/invoices/{id}/void', name: 'invoice_void', methods: ['POST'])]
    public function void(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }

        // Release the billed time entries + expenses so they can be re-invoiced.
        foreach ($invoice->getLineItems() as $line) {
            $line->getSourceTimeEntry()?->setBilledAt(null);
            $line->getSourceExpense()?->setBilledAt(null);
        }
        $invoice->setStatus(Invoice::STATUS_VOID);
        $this->em->flush();

        return $this->summary($invoice);
    }

    /**
     * Load the invoice and confirm the caller may manage it, or return the error
     * response to short-circuit with.
     */
    private function authorize(string $id, ?User $user): Invoice|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }
        $invoice = $this->invoices->find($id);
        $space = $invoice?->getSpace();
        if (
            null === $invoice || null === $space
            || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))
        ) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage invoices in this space.'], 403);
        }

        return $invoice;
    }

    /** The payment ledger + balance the PWA re-renders after a payment write. */
    private function paymentSummary(Invoice $invoice): JsonResponse
    {
        $payments = [];
        foreach ($invoice->getPayments() as $payment) {
            $payments[] = [
                'id' => (string) $payment->getId(),
                'amount' => $payment->getAmount(),
                'paidOn' => $payment->getPaidOn()->format('Y-m-d'),
                'method' => $payment->getMethod(),
                'note' => $payment->getNote(),
            ];
        }

        return $this->json([
            '@id' => '/invoices/' . $invoice->getId(),
            'id' => (string) $invoice->getId(),
            'status' => $invoice->getStatus(),
            'total' => $invoice->getTotal(),
            'amountPaid' => $invoice->getAmountPaid(),
            'balanceDue' => $invoice->getBalanceDue(),
            'payments' => $payments,
        ]);
    }

    private function summary(Invoice $invoice): JsonResponse
    {
        return $this->json([
            '@id' => '/invoices/' . $invoice->getId(),
            'id' => (string) $invoice->getId(),
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'paidAt' => $invoice->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'total' => $invoice->getTotal(),
        ]);
    }
}
