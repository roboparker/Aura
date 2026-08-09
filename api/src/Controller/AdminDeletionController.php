<?php

declare(strict_types=1);

namespace App\Controller;

use App\Deletion\AdminDeletionService;
use App\Deletion\SoftDeletable;
use App\Deletion\SoftDeletionService;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\AdminActionLogRepository;
use App\Service\SensitiveActionVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Site-admin deletion of another person's account, organization, or space.
 *
 * `ROLE_ADMIN` only (gated at `^/admin/deletions` in security.yaml) and step-up
 * verified on top: this is the one place in the product where a permanent,
 * un-undoable delete of somebody else's data is possible, so a stolen admin
 * cookie must not be enough.
 *
 * `immediate: true` bypasses the 30-day grace period. It exists here and
 * **nowhere else** — end users always get the window. The cases it's for are
 * erasure requests that can't wait and abuse takedowns where leaving content
 * live for a month isn't acceptable. Everything is written to
 * {@see \App\Entity\AdminActionLog} first, including the typed reason.
 */
class AdminDeletionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AdminDeletionService $deletion,
        private AdminActionLogRepository $auditLog,
        private SensitiveActionVerifier $verifier,
    ) {
    }

    /**
     * What deleting this user would strand: organizations they solely own and
     * shared spaces they solely administer. Shown before the action so the
     * admin can decide what goes with the account instead of finding out after.
     */
    #[Route('/admin/deletions/user-assets/{id}', name: 'admin_deletion_assets', methods: ['GET'])]
    public function assets(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'User not found.'], 404);
        }
        $target = $this->em->getRepository(User::class)->find($id);
        if (null === $target) {
            return $this->json(['error' => 'User not found.'], 404);
        }

        return $this->json([
            'user' => ['id' => (string) $target->getId(), 'email' => $target->getEmail()],
            ...$this->deletion->assetsOf($target),
        ]);
    }

    /**
     * Delete a target.
     *
     * Body: `{targetType, targetId, immediate, notifyOwner, reason, confirm,
     * currentPassword|totpCode}`. `confirm` must equal the target's label —
     * the same type-to-confirm bar a user clears to delete their own, because
     * an admin acting on someone else's data should not have an easier path.
     */
    #[Route('/admin/deletions', name: 'admin_deletion_perform', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] ?User $admin): JsonResponse
    {
        if (null === $admin) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $body = $this->body($request);

        $targetType = is_string($body['targetType'] ?? null) ? $body['targetType'] : '';
        $targetId = is_string($body['targetId'] ?? null) ? $body['targetId'] : '';
        $target = $this->resolve($targetType, $targetId);
        if (null === $target) {
            return $this->json(['error' => 'Target not found.'], 404);
        }

        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if ('' === $reason) {
            // Required, and free text rather than a picklist: the audit row is
            // read by a human months later trying to understand a decision.
            return $this->json(['error' => 'A reason is required — it goes into the audit log.'], 422);
        }

        $confirm = is_string($body['confirm'] ?? null) ? trim($body['confirm']) : '';
        if ($confirm !== $target->deletionLabel()) {
            return $this->json([
                'error' => sprintf('Type "%s" exactly to confirm.', $target->deletionLabel()),
            ], 422);
        }

        // An admin must not be able to delete their own account through the
        // admin surface — that would skip the churn survey and the step-up
        // flow the self-service path enforces, and reads as an accident.
        if ($target instanceof User && true === $target->getId()?->equals($admin->getId())) {
            return $this->json(
                ['error' => 'Use Settings → Danger zone to delete your own account.'],
                409,
            );
        }

        if (null !== ($stepUp = $this->verifier->verify($admin, $body))) {
            return $this->json(['error' => $stepUp[1]], $stepUp[0]);
        }

        $immediate = true === ($body['immediate'] ?? false);
        $notify = false !== ($body['notifyOwner'] ?? true);

        $log = $this->deletion->delete($target, $admin, $immediate, $notify, $reason);

        return $this->json([
            'ok' => true,
            'action' => $log->getAction(),
            'ownerNotified' => $log->wasOwnerNotified(),
            'auditId' => (string) $log->getId(),
        ]);
    }

    /** The audit trail, newest first. */
    #[Route('/admin/deletions/log', name: 'admin_deletion_log', methods: ['GET'])]
    public function log(): JsonResponse
    {
        $entries = [];
        foreach ($this->auditLog->recent() as $row) {
            $entries[] = [
                'id' => (string) $row->getId(),
                'actorEmail' => $row->getActorEmail(),
                'action' => $row->getAction(),
                'targetType' => $row->getTargetType(),
                'targetLabel' => $row->getTargetLabel(),
                'targetOwnerEmail' => $row->getTargetOwnerEmail(),
                'reason' => $row->getReason(),
                'ownerNotified' => $row->wasOwnerNotified(),
                'createdAt' => $row->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json(['entries' => $entries]);
    }

    private function resolve(string $targetType, string $targetId): ?SoftDeletable
    {
        if (!Uuid::isValid($targetId)) {
            return null;
        }
        $class = match ($targetType) {
            SoftDeletionService::TYPE_ORGANIZATION => Organization::class,
            SoftDeletionService::TYPE_SPACE => Space::class,
            SoftDeletionService::TYPE_ACCOUNT => User::class,
            default => null,
        };
        if (null === $class) {
            return null;
        }
        $entity = $this->em->getRepository($class)->find($targetId);

        // A personal space is an artefact of its owner's account, not a thing
        // with an independent existence — it goes when the account does.
        if ($entity instanceof Space && $entity->getIsPersonal()) {
            return null;
        }

        return $entity instanceof SoftDeletable ? $entity : null;
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
