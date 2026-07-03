<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Service\InvoicePdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Streams an {@see \App\Entity\Invoice} as a PDF (#445), rendered on demand by
 * {@see InvoicePdfRenderer}. Admin-reserved (invoices.read) and 404-hides a
 * non-member's target, like the rest of the invoicing surface.
 */
class InvoicePdfController extends AbstractController
{
    public function __construct(
        private InvoiceRepository $invoices,
        private SpacePermissionResolver $permissions,
        private InvoicePdfRenderer $renderer,
    ) {
    }

    #[Route('/invoices/{id}/pdf', name: 'invoice_pdf', methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] ?User $user): Response
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
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::READ)
        ) {
            return $this->json(['error' => 'You cannot view invoices in this space.'], 403);
        }

        $pdf = $this->renderer->render($invoice);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $this->renderer->filename($invoice)),
        ]);
    }
}
