<?php

namespace App\Controller;

use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\InvoiceLineItem;
use App\Entity\User;
use App\Repository\EstimateRepository;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use App\Service\EstimateMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Estimate lifecycle transitions (#652):
 *
 *  - send: assign the next per-space EST number, mint the public
 *    accept/decline token (sha256 at rest), move draft → sent, and email the
 *    client the link.
 *  - convert: copy the lines onto a fresh draft {@see Invoice} and back-link
 *    it — the quote → bill handoff. Declined estimates can't convert; an
 *    estimate converts at most once.
 *
 * Both are admin-reserved (invoices.update) and hide a non-member's target
 * behind a 404, like the rest of the access model.
 */
class EstimateActionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private EstimateRepository $estimates,
        private SpacePermissionResolver $permissions,
        private EstimateMailer $mailer,
    ) {
    }

    #[Route('/estimates/{id}/send', name: 'estimate_send', methods: ['POST'])]
    public function send(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $estimate = $this->authorize($id, $user);
        if ($estimate instanceof JsonResponse) {
            return $estimate;
        }
        if (Estimate::STATUS_DECLINED === $estimate->getStatus()) {
            return $this->json(['error' => 'A declined estimate cannot be re-sent.'], 409);
        }

        $space = $estimate->getSpace();
        if (null !== $space && null === $estimate->getNumber()) {
            $next = $this->estimates->countNumbered($space) + 1;
            $estimate->setNumber('EST-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT));
        }

        // Mint the shareable accept/decline token (sha256 at rest, rotated on
        // every send so the freshest email is authoritative).
        $plain = bin2hex(random_bytes(32));
        $estimate->setPublicToken(hash('sha256', $plain));
        if (Estimate::STATUS_DRAFT === $estimate->getStatus()) {
            $estimate->setStatus(Estimate::STATUS_SENT);
        }
        $estimate->setSentAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->mailer->sendEstimate($estimate, $plain);

        return $this->json([
            'id' => (string) $estimate->getId(),
            'number' => $estimate->getNumber(),
            'status' => $estimate->getStatus(),
            'token' => $plain,
            'publicUrl' => '/public/estimates/' . $plain,
        ], 201);
    }

    #[Route('/estimates/{id}/convert', name: 'estimate_convert', methods: ['POST'])]
    public function convert(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $estimate = $this->authorize($id, $user);
        if ($estimate instanceof JsonResponse) {
            return $estimate;
        }
        if (Estimate::STATUS_DECLINED === $estimate->getStatus()) {
            return $this->json(['error' => 'A declined estimate cannot be converted.'], 409);
        }
        if (null !== $estimate->getConvertedInvoice()) {
            return $this->json(['error' => 'This estimate was already converted.'], 409);
        }
        $space = $estimate->getSpace();
        $client = $estimate->getClient();
        if (null === $space || null === $client) {
            return $this->json(['error' => 'This estimate has no client.'], 422);
        }

        $invoice = (new Invoice())
            ->setSpace($space)
            ->setClient($client)
            ->setCurrency($estimate->getCurrency())
            ->setTaxRate($estimate->getTaxRate())
            ->setNotes($estimate->getNotes())
            ->setCreatedBy($user instanceof User ? $user : $estimate->getCreatedBy());

        $subtotal = 0;
        foreach ($estimate->getLineItems() as $line) {
            $amount = $line->getAmount();
            $subtotal += $amount;
            $invoice->addLineItem(
                (new InvoiceLineItem())
                    ->setDescription($line->getDescription())
                    ->setQuantity($line->getQuantity())
                    ->setUnitAmount($line->getUnitAmount())
                    ->setAmount($amount)
                    ->setPosition($line->getPosition()),
            );
        }
        $taxAmount = (int) round($subtotal * $estimate->getTaxRate() / 10000);
        $invoice->setSubtotal($subtotal);
        $invoice->setTaxAmount($taxAmount);
        $invoice->setTotal($subtotal + $taxAmount);

        $this->em->persist($invoice);
        $estimate->setConvertedInvoice($invoice);
        $this->em->flush();

        return $this->json([
            'estimate' => (string) $estimate->getId(),
            'invoice' => [
                '@id' => '/invoices/' . $invoice->getId(),
                'id' => (string) $invoice->getId(),
                'status' => $invoice->getStatus(),
                'total' => $invoice->getTotal(),
            ],
        ], 201);
    }

    /**
     * Load the estimate and confirm the caller may manage it, or return the
     * error response to short-circuit with.
     */
    private function authorize(string $id, ?User $user): Estimate|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Estimate not found.'], 404);
        }
        $estimate = $this->estimates->find($id);
        $space = $estimate?->getSpace();
        if (
            null === $estimate || null === $space
            || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))
        ) {
            return $this->json(['error' => 'Estimate not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage estimates in this space.'], 403);
        }

        return $estimate;
    }
}
