<?php

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\Payment\PaymentGatewayRegistry;
use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Service\InvoicePdfRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private PaymentGatewayRegistry $gateways,
        #[Autowire('%env(default::APP_FRONTEND_URL)%')]
        private string $frontendUrl,
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
                'taxRate' => $line->getTaxRate(),
            ];
        }

        $payments = [];
        foreach ($invoice->getPayments() as $payment) {
            $payments[] = [
                'amount' => $payment->getAmount(),
                'paidOn' => $payment->getPaidOn()->format('Y-m-d'),
                'method' => $payment->getMethod(),
            ];
        }

        $logoUrls = $invoice->getSpace()?->getInvoiceLogo()?->getVariantUrls() ?? [];

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
            'discountAmount' => $invoice->getDiscountAmount(),
            'taxAmount' => $invoice->getTaxAmount(),
            'taxBreakdown' => $invoice->getTaxBreakdown(),
            'total' => $invoice->getTotal(),
            'amountPaid' => $invoice->getAmountPaid(),
            'balanceDue' => $invoice->getBalanceDue(),
            'payments' => $payments,
            'pdfUrl' => '/public/invoices/' . $token . '/pdf',
            // Branding (#669): the logo is avatar-kind media on the public
            // /media/ route, so exposing the URL leaks nothing sensitive.
            'logoUrl' => $logoUrls['profile'] ?? array_values($logoUrls)[0] ?? null,
            'terms' => $invoice->getSpace()?->getInvoiceTerms(),
            // #stripe-mode: tell the client when this is a sandbox invoice, so a
            // test-card payment can never be mistaken for a real one.
            'testMode' => $this->gateways->default()?->isTestMode() ?? true,
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

    #[Route('/public/invoices/{token}/pay', name: 'public_invoice_pay', methods: ['POST'])]
    public function pay(string $token): JsonResponse
    {
        $invoice = $this->resolve($token);
        if (null === $invoice) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }
        if (Invoice::STATUS_PAID === $invoice->getStatus()) {
            return $this->json(['error' => 'This invoice is already paid.'], 409);
        }
        if ($invoice->getBalanceDue() <= 0) {
            return $this->json(['error' => 'Nothing to pay on this invoice.'], 409);
        }

        $gateway = $this->gateways->default();
        if (null === $gateway || !$gateway->isConfigured()) {
            return $this->json(['error' => 'Online payment is not available.'], 503);
        }
        // The invoice's business must have completed Stripe Connect onboarding,
        // so the payment settles to them rather than the platform (#connect).
        $space = $invoice->getSpace();
        if (null === $space || !$space->canAcceptClientPayments()) {
            return $this->json(['error' => 'This business has not set up online payments yet.'], 503);
        }

        $base = rtrim($this->frontendUrl, '/') . '/i/' . $token;
        try {
            $url = $gateway->createInvoicePayment($invoice, $base . '?paid=1', $base);
        } catch (BillingException $e) {
            return $this->json(['error' => 'Could not start payment: ' . $e->getMessage()], 502);
        }

        return $this->json(['provider' => $gateway->key(), 'url' => $url], 201);
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
