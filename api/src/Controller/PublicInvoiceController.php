<?php

namespace App\Controller;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\InvoicePdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public, unauthenticated invoice view (#445) a client reaches from the
 * shareable link minted by {@see InvoiceActionController::send()}. The token is
 * sha256-hashed at rest, so a leaked DB row can't reconstruct a link; an unknown
 * or void token 404s. Online payment (Stripe, then PayPal) attaches here later.
 */
class PublicInvoiceController extends AbstractController
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoicePdfRenderer $renderer,
    ) {
    }

    #[Route('/public/invoices/{token}', name: 'public_invoice_view', methods: ['GET'])]
    public function view(string $token): JsonResponse
    {
        $invoice = $this->resolve($token);
        if (null === $invoice) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }

        $lines = [];
        foreach ($invoice->getLineItems() as $line) {
            $lines[] = [
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unitAmount' => $line->getUnitAmount(),
                'amount' => $line->getAmount(),
            ];
        }

        return $this->json([
            'number' => $invoice->getNumber(),
            'status' => $invoice->getStatus(),
            'currency' => $invoice->getCurrency(),
            'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
            'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
            'from' => $invoice->getSpace()?->getName(),
            'billTo' => $invoice->getClient()?->getName(),
            'lineItems' => $lines,
            'subtotal' => $invoice->getSubtotal(),
            'taxAmount' => $invoice->getTaxAmount(),
            'total' => $invoice->getTotal(),
            'pdfUrl' => '/public/invoices/' . $token . '/pdf',
        ]);
    }

    #[Route('/public/invoices/{token}/pdf', name: 'public_invoice_pdf', methods: ['GET'])]
    public function pdf(string $token): Response
    {
        $invoice = $this->resolve($token);
        if (null === $invoice) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }

        return new Response($this->renderer->render($invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $this->renderer->filename($invoice)),
        ]);
    }

    private function resolve(string $token): ?Invoice
    {
        if ('' === trim($token)) {
            return null;
        }
        $invoice = $this->invoices->findByPublicTokenHash(hash('sha256', $token));
        if (null === $invoice || Invoice::STATUS_VOID === $invoice->getStatus()) {
            return null;
        }

        return $invoice;
    }
}
