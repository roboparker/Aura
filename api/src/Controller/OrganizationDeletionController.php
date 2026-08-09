<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CancellationFeedback;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Service\CancellationFeedbackRecorder;
use App\Service\OrganizationDeletionService;
use App\Service\SensitiveActionVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Organization deletion + restore (#billing Phase 1c).
 *
 * The most destructive action in the product — an org's spaces cascade, so
 * this reaches every board, task, page and comment its members ever wrote.
 * Four gates, each closing a different failure:
 *
 *  - **Owner only.** Admins manage members and settings; ending the account is
 *    the owner's call.
 *  - **Step-up** ({@see SensitiveActionVerifier}), so a stolen session cookie
 *    isn't enough — same bar as deleting a personal account or disarming 2FA.
 *  - **Type the name.** Guards against the wrong tab, not against an attacker.
 *  - **A 30-day grace period**, which is the one that actually protects other
 *    members' work: nothing is destroyed until it lapses, and any owner can
 *    reverse it.
 *
 * Deletion is not exposed as an API Platform `Delete` operation precisely
 * because none of the above fits an entity `security:` expression.
 */
class OrganizationDeletionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrganizationRepository $organizations,
        private OrganizationDeletionService $deletion,
        private SensitiveActionVerifier $verifier,
        private CancellationFeedbackRecorder $feedback,
    ) {
    }

    #[Route('/organizations/{id}/delete', name: 'organization_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireOwner($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if ($org->isDeleted()) {
            return $this->json(['error' => 'This organization is already scheduled for deletion.'], 409);
        }

        $body = $this->body($request);

        $confirm = is_string($body['confirmName'] ?? null) ? trim($body['confirmName']) : '';
        if ($confirm !== $org->getName()) {
            return $this->json(['error' => 'Type the organization name exactly to confirm.'], 422);
        }

        $reasonError = $this->feedback->reasonError($body);
        if (null !== $reasonError) {
            return $this->json(['error' => $reasonError], 422);
        }

        if (null !== ($stepUp = $this->verifier->verify($user, $body))) {
            return $this->json(['error' => $stepUp[1]], $stepUp[0]);
        }

        // Record before the state change: the feedback row's org FK is SET NULL,
        // so it survives the eventual purge as an anonymized data point.
        $this->feedback->record(CancellationFeedback::CONTEXT_ORGANIZATION_DELETION, $user, null, $body);

        $purgeAfter = $this->deletion->softDelete($org);

        return $this->json([
            'ok' => true,
            'status' => 'scheduled',
            'deletedAt' => $org->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            'purgeAfter' => $purgeAfter->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/organizations/{id}/restore', name: 'organization_restore', methods: ['POST'])]
    public function restore(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireOwner($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$org->isDeleted()) {
            return $this->json(['error' => 'This organization is not scheduled for deletion.'], 409);
        }

        // Step-up on restore too: bringing an account back turns access to
        // everyone's content back on, which is a security event in its own
        // right even though it isn't destructive.
        if (null !== ($stepUp = $this->verifier->verify($user, $this->body($request)))) {
            return $this->json(['error' => $stepUp[1]], $stepUp[0]);
        }

        $this->deletion->restore($org);

        return $this->json([
            'ok' => true,
            'status' => 'active',
            // Billing is deliberately not resurrected — the owner re-subscribes
            // if they want the plan back, rather than an undo silently starting
            // a charge.
            'billingRestored' => false,
        ]);
    }

    /**
     * Organizations the caller owns that are mid-deletion. A soft-deleted org
     * drops out of `GET /organizations`, so without this the owner has no way
     * back to the thing they need to restore.
     */
    #[Route('/organizations/deleted', name: 'organization_deleted_list', methods: ['GET'], priority: 10)]
    public function deleted(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $out = [];
        foreach ($this->organizations->restorableFor($user) as $org) {
            $out[] = [
                'id' => (string) $org->getId(),
                'name' => $org->getName(),
                'slug' => $org->getSlug(),
                'deletedAt' => $org->getDeletedAt()?->format(\DateTimeInterface::ATOM),
                'purgeAfter' => $org->getPurgeAfter()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json(['organizations' => $out]);
    }

    /**
     * Resolve the org and require ownership. Existence-hiding matches the rest
     * of the org API: non-members get 404, non-owner members 403. A
     * soft-deleted org stays resolvable so its owner can restore it.
     */
    private function requireOwner(string $id, ?User $user): Organization|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $org = $this->em->getRepository(Organization::class)->find($id);
        if (null === $org || !$org->hasMember($user)) {
            return $this->json(['error' => 'Organization not found.'], 404);
        }
        if (!$org->isOwner($user)) {
            return $this->json(['error' => 'Only an organization owner can delete or restore it.'], 403);
        }

        return $org;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
