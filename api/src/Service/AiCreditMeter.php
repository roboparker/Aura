<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiCreditBalance;
use App\Ai\AiUnavailableException;
use App\Ai\ChatResponse;
use App\Billing\PlanGate;
use App\Entity\AiCreditLedgerEntry;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The AI spend control (#827): reads an organization's monthly allowance from
 * its plan, and reserves against it *before* a model call so a mid-flight
 * failure cannot overspend.
 *
 * Until this shipped, `ai_credits_per_month` was declared in
 * {@see \App\Billing\PlanCatalog} and read by nothing — the pricing page sold
 * credits that were never granted or spent. This is the thing that reads it.
 *
 * ## Reserve, then reconcile
 *
 * 1. {@see reserve()} writes a `pending` row for the **most** the call could
 *    cost (prompt estimate + `maxOutputTokens`) and refuses outright if that
 *    won't fit. From that instant the amount counts against the balance.
 * 2. {@see settle()} rewrites the row to the provider's reported usage — nearly
 *    always less, releasing the difference.
 * 3. {@see release()} deletes the row when the call failed. A failed call is not
 *    a charge.
 *
 * Reserving first is what makes the ordering safe: charging afterwards would
 * mean a crash between the call and the write is free, and "free when it breaks"
 * is the failure mode that turns an agent loop into an unbounded bill.
 *
 * ## Why this bypasses the ORM, and why it locks
 *
 * Writes go through DBAL, like {@see UsageRecorder} over
 * {@see \App\Entity\UserUsageCounter}. Here it is load-bearing rather than
 * stylistic: reserving is *check the balance and insert, atomically*, and
 * without that atomicity two concurrent requests both see the last of the
 * allowance and both spend it. {@see reserve()} therefore takes a row lock on
 * the organization for the duration, which serialises reservations per account.
 * At chat volumes that costs nothing, and it is the difference between a cap
 * and a suggestion.
 *
 * ## Not behind the billing dark-launch flag
 *
 * Every other cap in {@see UsageLimiter} short-circuits to "allowed" while
 * `app.billing_enforcement_enabled` is false, so the freemium gate could ship
 * before Stripe was live. **This one is always on**, deliberately.
 *
 * The other caps protect revenue: too permissive and we undercharge, which is
 * recoverable. This one protects spend against a third party's meter: too
 * permissive and we are the ones being billed, without limit, by something a
 * language model drives. Those two failures do not deserve the same default.
 */
final class AiCreditMeter
{
    /**
     * How long a reservation counts before the balance stops honouring it.
     *
     * Sized well above the provider timeout (60s) so it can never expire under
     * a call that is still legitimately running, and well below anything a
     * person would wait, so a crashed process frees the credits by itself. The
     * balance query enforces this, which is what makes the cleanup sweep pure
     * housekeeping rather than something the product depends on.
     */
    private const RESERVATION_TTL_MINUTES = 15;

    public function __construct(
        private readonly Connection $connection,
        private readonly PlanGate $planGate,
        #[Autowire('%app.ai_tokens_per_credit%')]
        private readonly int $tokensPerCredit,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function tokensPerCredit(): int
    {
        return max(1, $this->tokensPerCredit);
    }

    /** The current UTC month, the window an allowance resets on. */
    public function currentPeriod(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m');
    }

    /**
     * The organization's allowance in tokens; null = unlimited.
     *
     * A calendar month, not the Stripe billing period. That is a deliberate
     * simplification: an account can hold an allowance with no subscription at
     * all (and every Free account does), so anchoring to a period that only
     * exists once someone has paid would leave the common case undefined.
     */
    public function allowanceTokens(Organization $organization): ?int
    {
        $credits = $this->planGate->organizationEntitlements($organization)->limit('ai_credits_per_month');
        if (null === $credits) {
            return null;
        }

        return max(0, $credits) * $this->tokensPerCredit();
    }

    public function balance(Organization $organization, ?\DateTimeImmutable $now = null): AiCreditBalance
    {
        $period = $this->currentPeriod($now);
        $id = $organization->getId();
        if (null === $id) {
            return new AiCreditBalance($period, 0, 0, 0, $this->tokensPerCredit());
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(tokens) FILTER (WHERE state = :settled), 0) AS settled_tokens,
                    COALESCE(SUM(tokens) FILTER (WHERE state = :pending AND expires_at > :now), 0) AS pending_tokens
                FROM ai_credit_ledger
                WHERE organization_id = :org AND period = :period
                SQL,
            [
                'settled' => AiCreditLedgerEntry::STATE_SETTLED,
                'pending' => AiCreditLedgerEntry::STATE_PENDING,
                'now' => $this->nowString($now),
                'org' => $id->toRfc4122(),
                'period' => $period,
            ],
        );

        return new AiCreditBalance(
            period: $period,
            allowanceTokens: $this->allowanceTokens($organization),
            settledTokens: $this->intOf($row['settled_tokens'] ?? 0),
            pendingTokens: $this->intOf($row['pending_tokens'] ?? 0),
            tokensPerCredit: $this->tokensPerCredit(),
        );
    }

    /**
     * Hold `$tokens` against the allowance and return the reservation id.
     *
     * The balance re-read happens *inside* the transaction that holds the
     * organization's row lock, so the answer cannot go stale between the check
     * and the insert. Callers must follow every successful reserve with exactly
     * one {@see settle()} or {@see release()}.
     *
     * @throws AiUnavailableException when the allowance can't cover it
     */
    public function reserve(
        Organization $organization,
        ?User $agent,
        int $tokens,
        string $provider,
        string $model,
        ?\DateTimeImmutable $now = null,
    ): string {
        $organizationId = $organization->getId();
        if (null === $organizationId) {
            throw AiUnavailableException::noAccount();
        }

        $period = $this->currentPeriod($now);
        $allowance = $this->allowanceTokens($organization);
        $reservationId = Uuid::v4()->toRfc4122();
        $at = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tokens = max(0, $tokens);

        $this->connection->beginTransaction();
        try {
            // Serialises reservations for this account. The row is only being
            // read — the lock exists to order the check-then-insert below, not
            // to change anything about the organization.
            $this->connection->executeQuery(
                'SELECT id FROM organization WHERE id = :org FOR UPDATE',
                ['org' => $organizationId->toRfc4122()],
            );

            if (null !== $allowance) {
                $used = $this->usedTokensLocked($organizationId->toRfc4122(), $period, $at);
                if ($used + $tokens > $allowance) {
                    $this->connection->rollBack();

                    throw AiUnavailableException::creditsExhausted(new AiCreditBalance(
                        period: $period,
                        allowanceTokens: $allowance,
                        settledTokens: $used,
                        pendingTokens: 0,
                        tokensPerCredit: $this->tokensPerCredit(),
                    ));
                }
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO ai_credit_ledger
                        (id, organization_id, agent_id, period, state, tokens, provider, model, created_at, expires_at)
                    VALUES
                        (:id, :org, :agent, :period, :state, :tokens, :provider, :model, :createdAt, :expiresAt)
                    SQL,
                [
                    'id' => $reservationId,
                    'org' => $organizationId->toRfc4122(),
                    'agent' => $agent?->getId()?->toRfc4122(),
                    'period' => $period,
                    'state' => AiCreditLedgerEntry::STATE_PENDING,
                    'tokens' => $tokens,
                    'provider' => $provider,
                    'model' => mb_substr($model, 0, 64),
                    'createdAt' => $at->format('Y-m-d H:i:s'),
                    'expiresAt' => $at->modify(sprintf('+%d minutes', self::RESERVATION_TTL_MINUTES))
                        ->format('Y-m-d H:i:s'),
                ],
            );

            $this->connection->commit();
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        return $reservationId;
    }

    /**
     * Reconcile a reservation to what the call actually cost.
     *
     * Guarded on `state = 'pending'` so a duplicate settle can't re-charge a
     * row that is already final, and so a reservation whose TTL lapsed under a
     * very slow call still settles rather than silently vanishing.
     */
    public function settle(string $reservationId, ChatResponse $response, ?\DateTimeImmutable $now = null): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE ai_credit_ledger
                SET state = :settled,
                    tokens = :tokens,
                    prompt_tokens = :promptTokens,
                    completion_tokens = :completionTokens,
                    model = :model,
                    settled_at = :settledAt
                WHERE id = :id AND state = :pending
                SQL,
            [
                'settled' => AiCreditLedgerEntry::STATE_SETTLED,
                'pending' => AiCreditLedgerEntry::STATE_PENDING,
                'tokens' => $response->totalTokens(),
                'promptTokens' => $response->promptTokens,
                'completionTokens' => $response->completionTokens,
                'model' => mb_substr($response->model, 0, 64),
                'settledAt' => $this->nowString($now),
                'id' => $reservationId,
            ],
        );
    }

    /**
     * Drop a reservation whose call never produced anything.
     *
     * Swallows its own failures: this runs in the catch arm of a call that has
     * already gone wrong, and turning a cleanup problem into the error the user
     * sees would hide the real one. A leaked row expires by itself anyway,
     * which is exactly what the TTL is insurance for.
     */
    public function release(string $reservationId): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM ai_credit_ledger WHERE id = :id AND state = :pending',
                ['id' => $reservationId, 'pending' => AiCreditLedgerEntry::STATE_PENDING],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not release an AI credit reservation', [
                'reservation' => $reservationId,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Delete expired reservations. Pure housekeeping — the balance already
     * ignores them, so this only keeps the table tidy.
     *
     * @return int rows removed
     */
    public function purgeExpiredReservations(?\DateTimeImmutable $now = null): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM ai_credit_ledger WHERE state = :pending AND expires_at <= :now',
            ['pending' => AiCreditLedgerEntry::STATE_PENDING, 'now' => $this->nowString($now)],
        );
    }

    private function usedTokensLocked(string $organizationId, string $period, \DateTimeImmutable $at): int
    {
        $used = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(tokens), 0)
                FROM ai_credit_ledger
                WHERE organization_id = :org
                  AND period = :period
                  AND (state = :settled OR (state = :pending AND expires_at > :now))
                SQL,
            [
                'org' => $organizationId,
                'period' => $period,
                'settled' => AiCreditLedgerEntry::STATE_SETTLED,
                'pending' => AiCreditLedgerEntry::STATE_PENDING,
                'now' => $at->format('Y-m-d H:i:s'),
            ],
        );

        return $this->intOf($used);
    }

    private function nowString(?\DateTimeImmutable $now): string
    {
        return ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** SUM() over a bigint comes back as a string on Postgres. */
    private function intOf(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
