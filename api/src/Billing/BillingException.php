<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Raised when a Stripe interaction fails (misconfiguration, transport error,
 * or a 4xx/5xx from the Stripe API). The {@see \App\Controller\BillingController}
 * maps it to a 502 so the PWA can surface "billing is temporarily
 * unavailable" without leaking internals.
 */
final class BillingException extends \RuntimeException
{
}
