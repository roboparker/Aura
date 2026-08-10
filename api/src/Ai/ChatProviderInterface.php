<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * A model provider we can ask for a chat completion (#827).
 *
 * Same seam as {@see \App\Billing\StripeGatewayInterface} and
 * {@see \App\Calendar\CalendarClientInterface}: a thin, domain-typed interface,
 * HTTP via Symfony HttpClient rather than a vendor SDK, and an in-memory double
 * for tests. {@see ChatProviderRegistry} routes by name so a second model drops
 * in as one class.
 *
 * **Nothing provider-specific crosses this boundary.** Message shapes, tool
 * schemas, streaming envelopes and error payloads all stay inside an
 * implementation. The one unavoidable leak is the model *identifier*, which is
 * a bare string — call sites should take {@see defaultModel()} rather than
 * hard-coding one, so swapping a model is a config change.
 */
interface ChatProviderInterface
{
    /** Stable key this provider is registered under, e.g. `openai`. */
    public function name(): string;

    /**
     * Whether this instance actually has credentials. False on a fresh
     * checkout, the same way the Stripe gateway and VAPID keys are blank —
     * callers degrade instead of throwing.
     */
    public function isConfigured(): bool;

    /** The model used when a caller doesn't pin one. */
    public function defaultModel(): string;

    /**
     * @throws ChatProviderException on transport failure, a refused request, or
     *                               a response this provider can't parse. The
     *                               caller releases its credit reservation on
     *                               this — a failed call must not be charged.
     */
    public function complete(ChatRequest $request): ChatResponse;
}
