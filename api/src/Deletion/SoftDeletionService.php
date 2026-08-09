<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Organization;
use App\Entity\RestoreToken;
use App\Entity\Space;
use App\Entity\User;
use App\Repository\RestoreTokenRepository;
use App\Service\DeletionNoticeMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The shared half of deleting an organization, a space, or an account: stamp
 * the grace window, mint a restore token, email the link.
 *
 * Type-specific work (cancelling a subscription, queueing exports, reassigning
 * authorship) stays with the type's own service; this is only the part all
 * three must do identically, because a difference between them would show up as
 * "my space had a restore link but my account didn't".
 */
final class SoftDeletionService
{
    public const TYPE_ORGANIZATION = 'organization';
    public const TYPE_SPACE = 'space';
    public const TYPE_ACCOUNT = 'account';

    public function __construct(
        private EntityManagerInterface $em,
        private RestoreTokenRepository $tokens,
        private DeletionNoticeMailer $mailer,
        private LoggerInterface $logger,
        #[Autowire('%app.deletion_grace_days%')]
        private int $graceDays,
    ) {
    }

    public function graceDays(): int
    {
        return $this->graceDays;
    }

    /**
     * Put the target into its grace period and email the restore link to
     * `$recipients`. Returns the instant it becomes purgeable — what the caller
     * shows the user as "restorable until".
     *
     * Re-deleting something already deleted keeps the original window: the date
     * the owner was shown is the one that binds, and a second delete shouldn't
     * quietly extend it.
     *
     * @param list<User> $recipients who gets the link (requester, plus other
     *                               owners/admins who didn't ask for this and
     *                               deserve a way to undo it)
     */
    public function schedule(SoftDeletable $target, array $recipients): \DateTimeImmutable
    {
        $existing = $target->getPurgeAfter();
        if ($target->isDeleted() && null !== $existing) {
            return $existing;
        }

        $now = new \DateTimeImmutable();
        $purgeAfter = $now->modify(sprintf('+%d days', $this->graceDays));
        $target->markDeleted($now, $purgeAfter);

        // Retire any token from a previous delete/restore cycle before minting
        // a new one, so an old email can't restore against the new window.
        $this->retireTokens($target);

        $plain = bin2hex(random_bytes(32));
        $token = new RestoreToken(
            $target->deletionTargetType(),
            $this->targetIdOf($target),
            $target->deletionLabel(),
            hash('sha256', $plain),
            $purgeAfter,
        );
        $this->em->persist($token);
        $this->em->flush();

        // Best-effort: a mail failure must not leave the target un-deleted (the
        // user asked for this and the state change already landed), but the
        // link is the only way back, so a failure is worth shouting about.
        foreach ($recipients as $recipient) {
            try {
                $this->mailer->sendDeletionScheduled($recipient, $target, $plain, $purgeAfter);
            } catch (\Throwable $e) {
                $this->logger->error('Could not send the {type} deletion notice to {email}: {error}', [
                    'type' => $target->deletionTargetType(),
                    'email' => $recipient->getEmail(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $purgeAfter;
    }

    /**
     * Resolve a plaintext restore token to its row, or null when unknown.
     * Callers distinguish used/expired via the row itself so the landing page
     * can say something useful; an unknown token stays a flat 404.
     */
    public function findToken(string $plainToken): ?RestoreToken
    {
        if ('' === $plainToken) {
            return null;
        }

        return $this->tokens->findByTokenHash(hash('sha256', $plainToken));
    }

    /** The entity a token points at, or null if it's already been purged. */
    public function resolveTarget(RestoreToken $token): ?SoftDeletable
    {
        $class = match ($token->getTargetType()) {
            self::TYPE_ORGANIZATION => Organization::class,
            self::TYPE_SPACE => Space::class,
            self::TYPE_ACCOUNT => User::class,
            default => null,
        };
        if (null === $class) {
            return null;
        }

        $entity = $this->em->getRepository($class)->find($token->getTargetId());

        return $entity instanceof SoftDeletable ? $entity : null;
    }

    /**
     * Take the target back out of its grace period and spend the token.
     * Returns false when the token can't restore anything — used, expired, or
     * the target is already purged or already live.
     */
    public function restore(RestoreToken $token): bool
    {
        if ($token->isUsed() || $token->isExpired()) {
            return false;
        }
        $target = $this->resolveTarget($token);
        if (null === $target || !$target->isDeleted()) {
            return false;
        }

        $target->clearDeleted();
        $token->markUsed();
        $this->em->flush();

        return true;
    }

    /** Invalidate every outstanding link for a target. */
    public function retireTokens(SoftDeletable $target): void
    {
        foreach ($this->tokens->findForTarget($target->deletionTargetType(), $this->targetIdOf($target)) as $token) {
            $this->em->remove($token);
        }
    }

    private function targetIdOf(SoftDeletable $target): \Symfony\Component\Uid\Uuid
    {
        $id = match (true) {
            $target instanceof Organization => $target->getId(),
            $target instanceof Space => $target->getId(),
            $target instanceof User => $target->getId(),
            default => null,
        };
        if (null === $id) {
            throw new \LogicException('Cannot schedule deletion for an unpersisted entity.');
        }

        return $id;
    }
}
