<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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

        $space = $invoice->getSpace();
        if (null !== $space && null === $invoice->getNumber()) {
            $next = $this->invoices->countNumbered($space) + 1;
            $invoice->setNumber('INV-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT));
        }
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

        $invoice->setStatus(Invoice::STATUS_PAID);
        $invoice->setPaidAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->summary($invoice);
    }

    #[Route('/invoices/{id}/void', name: 'invoice_void', methods: ['POST'])]
    public function void(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $invoice = $this->authorize($id, $user);
        if ($invoice instanceof JsonResponse) {
            return $invoice;
        }

        // Release the billed time entries so the hours can be re-invoiced.
        foreach ($invoice->getLineItems() as $line) {
            $line->getSourceTimeEntry()?->setBilledAt(null);
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
