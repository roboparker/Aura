<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * An agent cannot answer, for a reason that is about *us* rather than the
 * provider (#827) — no plan entitlement, no configured model, no allowance
 * left.
 *
 * Kept apart from {@see ChatProviderException} because the two call for
 * opposite handling: a provider failure is a blip worth retrying and worth
 * apologising for, whereas these are settled facts about the account that
 * retrying will not change and that the user can often act on.
 *
 * `reason` is a stable machine key so the PWA can render the right call to
 * action — an upgrade prompt reads very differently from "the operator hasn't
 * finished setting this up".
 */
final class AiUnavailableException extends \RuntimeException
{
    /** The plan doesn't include AI. Actionable: upgrade. */
    public const REASON_PLAN = 'plan_not_entitled';

    /** The monthly allowance is spent. Actionable: wait, or upgrade. */
    public const REASON_CREDITS = 'credits_exhausted';

    /** No model provider is configured on this instance. Operator's problem. */
    public const REASON_PROVIDER = 'provider_not_configured';

    /** The agent isn't attached to an account that could hold an allowance. */
    public const REASON_NO_ACCOUNT = 'no_account';

    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function planNotEntitled(): self
    {
        return new self(
            'This plan does not include AI agents. Upgrade to Business to use them.',
            self::REASON_PLAN,
        );
    }

    public static function creditsExhausted(AiCreditBalance $balance): self
    {
        return new self(
            sprintf(
                'This month\'s AI credits are used up (%d of %s). They reset at the start of next month.',
                $balance->usedCredits(),
                null === $balance->allowanceCredits() ? 'unlimited' : (string) $balance->allowanceCredits(),
            ),
            self::REASON_CREDITS,
        );
    }

    public static function providerNotConfigured(): self
    {
        return new self('No AI model is configured on this instance.', self::REASON_PROVIDER);
    }

    public static function noAccount(): self
    {
        return new self('This agent is not attached to an account with an AI allowance.', self::REASON_NO_ACCOUNT);
    }
}
