<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * An organization's AI allowance for one month, in tokens, with a credit view
 * for humans (#827).
 *
 * Tokens are the ledger's unit because they are what providers bill in; credits
 * are what the pricing page sells. Converting only here — never in the ledger —
 * keeps rounding out of the accumulated total, so a month of small charges
 * can't drift from the sum of its parts.
 *
 * `allowanceTokens === null` means unlimited; `0` means the plan grants no AI
 * at all, which is the Free and Pro default and therefore the common case.
 */
final class AiCreditBalance
{
    public function __construct(
        public readonly string $period,
        public readonly ?int $allowanceTokens,
        /** Charges reconciled to real provider usage. */
        public readonly int $settledTokens,
        /** Reservations in flight — already committed, not yet reconciled. */
        public readonly int $pendingTokens,
        public readonly int $tokensPerCredit,
    ) {
    }

    public function isUnlimited(): bool
    {
        return null === $this->allowanceTokens;
    }

    /**
     * Everything that counts against the allowance. In-flight reservations are
     * included on purpose: they are the only reason two concurrent requests
     * can't each spend the last of the month.
     */
    public function usedTokens(): int
    {
        return $this->settledTokens + $this->pendingTokens;
    }

    /** Null when unlimited. Never negative — an overspend reads as zero left. */
    public function remainingTokens(): ?int
    {
        if (null === $this->allowanceTokens) {
            return null;
        }

        return max(0, $this->allowanceTokens - $this->usedTokens());
    }

    public function canAfford(int $tokens): bool
    {
        $remaining = $this->remainingTokens();

        return null === $remaining || $remaining >= $tokens;
    }

    /**
     * Used credits, rounded **up**: a partial credit spent is a credit spent,
     * and rounding down would let a long tail of small calls show as zero
     * usage against a plan that is quietly being consumed.
     */
    public function usedCredits(): int
    {
        return (int) ceil($this->usedTokens() / max(1, $this->tokensPerCredit));
    }

    /** Null when unlimited. */
    public function allowanceCredits(): ?int
    {
        if (null === $this->allowanceTokens) {
            return null;
        }

        return intdiv($this->allowanceTokens, max(1, $this->tokensPerCredit));
    }

    /**
     * @return array{
     *     period: string,
     *     unlimited: bool,
     *     usedCredits: int,
     *     allowanceCredits: int|null,
     *     usedTokens: int,
     *     allowanceTokens: int|null,
     *     remainingTokens: int|null,
     *     tokensPerCredit: int
     * }
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'unlimited' => $this->isUnlimited(),
            'usedCredits' => $this->usedCredits(),
            'allowanceCredits' => $this->allowanceCredits(),
            'usedTokens' => $this->usedTokens(),
            'allowanceTokens' => $this->allowanceTokens,
            'remainingTokens' => $this->remainingTokens(),
            'tokensPerCredit' => $this->tokensPerCredit,
        ];
    }
}
