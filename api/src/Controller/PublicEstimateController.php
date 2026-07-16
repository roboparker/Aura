<?php

namespace App\Controller;

use App\Entity\Estimate;
use App\Repository\EstimateRepository;
use App\Service\EstimateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public, unauthenticated estimate view (#652) a client reaches from the
 * shareable link minted by {@see EstimateActionController::send()} — plus the
 * accept/decline actions the client decides right on the page. The token is
 * sha256-hashed at rest; an unknown token 404s; a decision is final (the
 * space re-sends a fresh estimate to renegotiate). Deciding emails the
 * estimate's creator so the team hears about it immediately.
 */
class PublicEstimateController extends AbstractController
{
    public function __construct(
        private EstimateRepository $estimates,
        private EntityManagerInterface $em,
        private EstimateMailer $mailer,
    ) {
    }

    #[Route('/public/estimates/{token}', name: 'public_estimate_view', methods: ['GET'])]
    public function view(string $token): JsonResponse
    {
        $estimate = $this->resolve($token);
        if (null === $estimate) {
            return $this->json(['error' => 'Estimate not found.'], 404);
        }

        $lines = [];
        foreach ($estimate->getLineItems() as $line) {
            $lines[] = [
                'description' => $line->getDescription(),
                'quantity' => $line->getQuantity(),
                'unitAmount' => $line->getUnitAmount(),
                'amount' => $line->getAmount(),
            ];
        }

        $logoUrls = $estimate->getSpace()?->getInvoiceLogo()?->getVariantUrls() ?? [];

        return $this->json([
            'number' => $estimate->getNumber(),
            'status' => $estimate->getStatus(),
            'currency' => $estimate->getCurrency(),
            'from' => $estimate->getSpace()?->getName(),
            'billTo' => $estimate->getClient()?->getName(),
            'notes' => $estimate->getNotes(),
            'lineItems' => $lines,
            'subtotal' => $estimate->getSubtotal(),
            'taxAmount' => $estimate->getTaxAmount(),
            'total' => $estimate->getTotal(),
            'decidedAt' => $estimate->getDecidedAt()?->format(\DateTimeInterface::ATOM),
            // Branding (#669): shared with invoices — the space logo + terms.
            'logoUrl' => $logoUrls['profile'] ?? array_values($logoUrls)[0] ?? null,
            'terms' => $estimate->getSpace()?->getInvoiceTerms(),
        ]);
    }

    #[Route('/public/estimates/{token}/accept', name: 'public_estimate_accept', methods: ['POST'])]
    public function accept(string $token): JsonResponse
    {
        return $this->decide($token, Estimate::STATUS_ACCEPTED);
    }

    #[Route('/public/estimates/{token}/decline', name: 'public_estimate_decline', methods: ['POST'])]
    public function decline(string $token): JsonResponse
    {
        return $this->decide($token, Estimate::STATUS_DECLINED);
    }

    private function decide(string $token, string $status): JsonResponse
    {
        $estimate = $this->resolve($token);
        if (null === $estimate) {
            return $this->json(['error' => 'Estimate not found.'], 404);
        }
        if (Estimate::STATUS_SENT !== $estimate->getStatus()) {
            return $this->json(['error' => 'This estimate has already been decided.'], 409);
        }

        $estimate->setStatus($status);
        $estimate->setDecidedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Tell the team right away (best-effort).
        $this->mailer->sendDecision($estimate);

        return $this->json([
            'status' => $estimate->getStatus(),
            'decidedAt' => $estimate->getDecidedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    private function resolve(string $token): ?Estimate
    {
        if ('' === trim($token)) {
            return null;
        }

        return $this->estimates->findByPublicTokenHash(hash('sha256', $token));
    }
}
