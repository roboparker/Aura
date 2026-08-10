<?php

declare(strict_types=1);

namespace App\Service;

use App\Ai\AiUnavailableException;
use App\Ai\ChatMessage;
use App\Ai\ChatProviderException;
use App\Ai\ChatProviderRegistry;
use App\Ai\ChatRequest;
use App\Ai\ChatResponse;
use App\Billing\PlanGate;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\User;

/**
 * The one way to make an agent say something (#827).
 *
 * Everything an AI call has to satisfy is enforced here, in one place, so no
 * future caller can reach a provider without paying for it: plan entitlement,
 * a configured model, and a credit reservation that is settled or released
 * whatever happens. Step 3's chat storage calls this and nothing else.
 *
 * Callers never name a provider or a model. The registry picks the provider and
 * the provider names its own default model, so switching models is configuration
 * rather than a code change — and the provider-shaped vocabulary stays behind
 * {@see \App\Ai\ChatProviderInterface}.
 */
final class AgentChatService
{
    public function __construct(
        private readonly ChatProviderRegistry $providers,
        private readonly AiCreditMeter $meter,
        private readonly PlanGate $planGate,
    ) {
    }

    /**
     * Whether an agent in this space could answer at all right now — the same
     * checks {@see reply()} makes, minus the reservation.
     *
     * Exists so a UI can disable a composer with a reason instead of letting
     * someone type a message that was never going to be answered.
     */
    public function unavailableReason(Space $space): ?AiUnavailableException
    {
        $organization = $space->getOrganization();
        if (null === $organization) {
            return AiUnavailableException::noAccount();
        }
        if (!$this->planGate->organizationEntitlements($organization)->can('ai_assist')) {
            return AiUnavailableException::planNotEntitled();
        }
        if (!$this->providers->hasConfiguredProvider()) {
            return AiUnavailableException::providerNotConfigured();
        }
        $balance = $this->meter->balance($organization);
        if (!$balance->canAfford(1)) {
            return AiUnavailableException::creditsExhausted($balance);
        }

        return null;
    }

    /**
     * Ask `$agent` to answer, charging the space's account.
     *
     * @param list<ChatMessage> $messages ordered oldest-first, including
     *                                    whatever system instruction the caller
     *                                    wants the agent to operate under
     *
     * @throws AiUnavailableException when the account can't or may not spend
     * @throws ChatProviderException  when the model call itself failed; the
     *                                reservation has already been released, so
     *                                a retry starts from a clean balance
     */
    public function reply(
        User $agent,
        Space $space,
        array $messages,
        int $maxOutputTokens = ChatRequest::DEFAULT_MAX_OUTPUT_TOKENS,
    ): ChatResponse {
        $organization = $this->accountFor($space);

        // Entitlement before anything else: a plan that doesn't include AI
        // should be told so, not told it is out of the zero credits it has.
        if (!$this->planGate->organizationEntitlements($organization)->can('ai_assist')) {
            throw AiUnavailableException::planNotEntitled();
        }

        $provider = $this->providers->preferred();
        if (null === $provider) {
            throw AiUnavailableException::providerNotConfigured();
        }

        $request = new ChatRequest(
            model: $provider->defaultModel(),
            messages: $messages,
            maxOutputTokens: max(1, $maxOutputTokens),
        );

        // Reserve the worst case, then call. The other order — call, then
        // charge — makes every crashed or timed-out request free, which is
        // precisely how an agent loop turns into an unbounded bill.
        $reservation = $this->meter->reserve(
            $organization,
            $agent->isAgent() ? $agent : null,
            $request->estimatedTotalTokens(),
            $provider->name(),
            $request->model,
        );

        try {
            $response = $provider->complete($request);
        } catch (\Throwable $e) {
            // Includes failures that are not ChatProviderException. Anything
            // that stops us knowing what the call cost must not leave the
            // account holding a reservation it can never spend.
            $this->meter->release($reservation);

            throw $e;
        }

        $this->meter->settle($reservation, $response);

        return $response;
    }

    private function accountFor(Space $space): Organization
    {
        $organization = $space->getOrganization();
        if (null === $organization) {
            throw AiUnavailableException::noAccount();
        }

        return $organization;
    }
}
