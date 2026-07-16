<?php

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\Payment\PaymentGatewayRegistry;
use App\Entity\Client;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Service\EstimateMailer;
use App\Service\InvoicePdfRenderer;
use App\Service\Mail\MailDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Client portal (#674): one tokenized link a client keeps, listing every
 * (non-draft) invoice and estimate they've received — instead of hunting
 * through per-document emails. The token is sha256-hashed at rest like the
 * per-document tokens; rotating (re-minting) invalidates the old link.
 *
 * Documents are actioned through portal-scoped routes (PDF, pay,
 * accept/decline) because per-document plaintext tokens can't be recovered
 * from their hashes — the portal token itself is the credential, and each
 * document route re-checks the document belongs to the portal's client.
 *
 * Minting/rotating the link is admin-reserved (invoices.update), same bar
 * as the invoice lifecycle actions.
 */
class ClientPortalController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpacePermissionResolver $permissions,
        private InvoicePdfRenderer $renderer,
        private PaymentGatewayRegistry $gateways,
        private EstimateMailer $estimateMailer,
        private MailDispatcher $mail,
        #[Autowire('%env(default::APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    #[Route('/clients/{id}/portal-link', name: 'client_portal_link', methods: ['POST'], priority: 10)]
    public function mint(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage clients in this space.'], 403);
        }

        // Rotate: the freshest link is the only working one.
        $plain = bin2hex(random_bytes(32));
        $client->setPortalToken(hash('sha256', $plain));
        $this->em->flush();

        $url = rtrim($this->frontendUrl, '/') . '/portal/' . $plain;

        $payload = $request->toArray();
        $emailed = false;
        $to = $client->getEmail();
        if (true === ($payload['send'] ?? false) && null !== $to && '' !== trim($to)) {
            $email = (new TemplatedEmail())
                ->to($to)
                ->subject(sprintf('Your billing portal at %s', $space->getName()))
                ->htmlTemplate('emails/client_portal.html.twig')
                ->textTemplate('emails/client_portal.txt.twig')
                ->context([
                    'client' => $client,
                    'fromName' => $space->getName(),
                    'portalUrl' => $url,
                ]);
            $this->mail->send($email);
            $emailed = true;
        }

        return $this->json(['token' => $plain, 'url' => $url, 'emailed' => $emailed], 201);
    }

    #[Route('/portal/{token}', name: 'client_portal_view', methods: ['GET'])]
    public function view(string $token): JsonResponse
    {
        $client = $this->resolveClient($token);
        if (null === $client) {
            return $this->json(['error' => 'Portal not found.'], 404);
        }
        $space = $client->getSpace();

        $invoices = [];
        /** @var list<Invoice> $invoiceRows */
        $invoiceRows = $this->em->getRepository(Invoice::class)
            ->findBy(['client' => $client], ['createdAt' => 'DESC']);
        foreach ($invoiceRows as $invoice) {
            if (in_array($invoice->getStatus(), [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)) {
                continue; // drafts are internal; void is dead paper
            }
            $invoices[] = [
                'id' => (string) $invoice->getId(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus(),
                'issueDate' => $invoice->getIssueDate()?->format('Y-m-d'),
                'dueDate' => $invoice->getDueDate()?->format('Y-m-d'),
                'currency' => $invoice->getCurrency(),
                'total' => $invoice->getTotal(),
                'amountPaid' => $invoice->getAmountPaid(),
                'balanceDue' => $invoice->getBalanceDue(),
            ];
        }

        $estimates = [];
        /** @var list<Estimate> $estimateRows */
        $estimateRows = $this->em->getRepository(Estimate::class)
            ->findBy(['client' => $client], ['createdAt' => 'DESC']);
        foreach ($estimateRows as $estimate) {
            if (Estimate::STATUS_DRAFT === $estimate->getStatus()) {
                continue;
            }
            $estimates[] = [
                'id' => (string) $estimate->getId(),
                'number' => $estimate->getNumber(),
                'status' => $estimate->getStatus(),
                'currency' => $estimate->getCurrency(),
                'total' => $estimate->getTotal(),
                'decidedAt' => $estimate->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        $logoUrls = $space?->getInvoiceLogo()?->getVariantUrls() ?? [];

        return $this->json([
            'clientName' => $client->getName(),
            'from' => $space?->getName(),
            'logoUrl' => $logoUrls['profile'] ?? array_values($logoUrls)[0] ?? null,
            'invoices' => $invoices,
            'estimates' => $estimates,
        ]);
    }

    #[Route('/portal/{token}/invoices/{id}/pdf', name: 'client_portal_invoice_pdf', methods: ['GET'])]
    public function invoicePdf(string $token, string $id): Response
    {
        $invoice = $this->resolveInvoice($token, $id);
        if (null === $invoice) {
            return $this->json(['error' => 'Invoice not found.'], 404);
        }

        return new Response($this->renderer->render($invoice), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->renderer->filename($invoice) . '"',
        ]);
    }

    #[Route('/portal/{token}/invoices/{id}/pay', name: 'client_portal_invoice_pay', methods: ['POST'])]
    public function invoicePay(string $token, string $id): JsonResponse
    {
        $invoice = $this->resolveInvoice($token, $id);
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

        $base = rtrim($this->frontendUrl, '/') . '/portal/' . $token;
        try {
            $url = $gateway->createInvoicePayment($invoice, $base . '?paid=1', $base);
        } catch (BillingException $e) {
            return $this->json(['error' => 'Could not start payment: ' . $e->getMessage()], 502);
        }

        return $this->json(['provider' => $gateway->key(), 'url' => $url], 201);
    }

    #[Route('/portal/{token}/estimates/{id}/accept', name: 'client_portal_estimate_accept', methods: ['POST'])]
    public function estimateAccept(string $token, string $id): JsonResponse
    {
        return $this->decideEstimate($token, $id, Estimate::STATUS_ACCEPTED);
    }

    #[Route('/portal/{token}/estimates/{id}/decline', name: 'client_portal_estimate_decline', methods: ['POST'])]
    public function estimateDecline(string $token, string $id): JsonResponse
    {
        return $this->decideEstimate($token, $id, Estimate::STATUS_DECLINED);
    }

    private function decideEstimate(string $token, string $id, string $status): JsonResponse
    {
        $estimate = $this->resolveEstimate($token, $id);
        if (null === $estimate) {
            return $this->json(['error' => 'Estimate not found.'], 404);
        }
        if (Estimate::STATUS_SENT !== $estimate->getStatus()) {
            return $this->json(['error' => 'This estimate has already been decided.'], 409);
        }

        $estimate->setStatus($status);
        $estimate->setDecidedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Tell the team right away (best-effort) — same as the public page.
        $this->estimateMailer->sendDecision($estimate);

        return $this->json([
            'status' => $estimate->getStatus(),
            'decidedAt' => $estimate->getDecidedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function resolveClient(string $token): ?Client
    {
        if ('' === trim($token)) {
            return null;
        }

        return $this->em->getRepository(Client::class)
            ->findOneBy(['portalToken' => hash('sha256', $token)]);
    }

    /** The invoice, only when it belongs to the portal's client and isn't internal. */
    private function resolveInvoice(string $token, string $id): ?Invoice
    {
        $client = $this->resolveClient($token);
        if (null === $client || !Uuid::isValid($id)) {
            return null;
        }
        $invoice = $this->em->getRepository(Invoice::class)->find($id);
        if (
            null === $invoice
            || true !== $invoice->getClient()?->getId()?->equals($client->getId())
            || in_array($invoice->getStatus(), [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID], true)
        ) {
            return null;
        }

        return $invoice;
    }

    /** The estimate, only when it belongs to the portal's client and isn't a draft. */
    private function resolveEstimate(string $token, string $id): ?Estimate
    {
        $client = $this->resolveClient($token);
        if (null === $client || !Uuid::isValid($id)) {
            return null;
        }
        $estimate = $this->em->getRepository(Estimate::class)->find($id);
        if (
            null === $estimate
            || true !== $estimate->getClient()?->getId()?->equals($client->getId())
            || Estimate::STATUS_DRAFT === $estimate->getStatus()
        ) {
            return null;
        }

        return $estimate;
    }
}
