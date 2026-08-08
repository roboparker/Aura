<?php

declare(strict_types=1);

namespace App\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Renders {@see PlanCatalog} as the TypeScript module the public pricing page
 * imports (`pwa/lib/planEntitlements.generated.ts`).
 *
 * Why generate rather than share: `/pricing` is public and statically built,
 * while the entitlement API is authenticated and per-space — a visitor has no
 * space to read entitlements from, and the PWA image is built in CI with no API
 * reachable. So the page needs its own copy of the matrix, and a hand-kept copy
 * silently drifts (it already had: Pro advertised priority support, which the
 * catalog grants only to Business and up). Generating it makes the drift
 * impossible instead of merely detectable, and CI re-runs the generator and
 * fails on a diff.
 *
 * Only the *entitlements* are generated. Labels, taglines, card bullets and the
 * table's grouping stay hand-written in `planTiers.ts` — they're marketing copy,
 * not facts the server knows.
 *
 * Output must be byte-stable: it is compared with `git diff` in CI, so anything
 * order-dependent here would show up as a phantom failure. Every loop below
 * walks a fixed constant order, never a hash order.
 */
final class PlanMatrixExporter
{
    /** Kept in sync with the header the generated file carries. */
    public const REGENERATE_HINT = 'scripts/gen-plan-matrix.sh';

    public function __construct(
        private readonly PlanCatalog $catalog,
        #[Autowire('%app.plan_price_pro_monthly_cents%')]
        private readonly int $proMonthlyCents,
        #[Autowire('%app.plan_price_business_monthly_cents%')]
        private readonly int $businessMonthlyCents,
    ) {
    }

    public function export(): string
    {
        $lines = [
            '/**',
            ' * GENERATED FILE — DO NOT EDIT BY HAND.',
            ' *',
            ' * Source of truth: api/src/Billing/PlanCatalog.php',
            ' * Regenerate with: ' . self::REGENERATE_HINT,
            ' *',
            ' * CI regenerates this and fails if the committed copy differs, so a plan',
            ' * change on the server cannot ship a stale public pricing page.',
            ' */',
            '',
            $this->tsConstArray('PLAN_KEYS', array_map(
                static fn (Plan $plan): string => $plan->value,
                Plan::cases(),
            )),
            'export type PlanKey = (typeof PLAN_KEYS)[number];',
            '',
            $this->tsConstArray('PLAN_FEATURES', PlanCatalog::FEATURES),
            'export type PlanFeature = (typeof PLAN_FEATURES)[number];',
            '',
            $this->tsConstArray('PLAN_LIMITS', PlanCatalog::LIMITS),
            'export type PlanLimit = (typeof PLAN_LIMITS)[number];',
            '',
            'export interface PlanEntitlements {',
            '  features: Record<PlanFeature, boolean>;',
            '  /** null = unlimited (matching PlanEntitlements::isUnlimited). */',
            '  limits: Record<PlanLimit, number | null>;',
            '}',
            '',
            '/** Monthly list price in cents; null = sales-led / custom. */',
            'export const PLAN_MONTHLY_CENTS: Record<PlanKey, number | null> = {',
        ];

        foreach (Plan::cases() as $plan) {
            $cents = $this->monthlyCents($plan);
            $lines[] = sprintf('  %s: %s,', $plan->value, $cents ?? 'null');
        }

        $lines[] = '};';
        $lines[] = '';
        $lines[] = 'export const PLAN_ENTITLEMENTS: Record<PlanKey, PlanEntitlements> = {';

        foreach (Plan::cases() as $plan) {
            $entitlements = $this->catalog->for($plan);

            $lines[] = sprintf('  %s: {', $plan->value);
            $lines[] = '    features: {';
            foreach (PlanCatalog::FEATURES as $feature) {
                $lines[] = sprintf(
                    '      %s: %s,',
                    $feature,
                    $entitlements->can($feature) ? 'true' : 'false',
                );
            }
            $lines[] = '    },';
            $lines[] = '    limits: {';
            foreach (PlanCatalog::LIMITS as $limit) {
                $value = $entitlements->limit($limit);

                // `null` means unlimited on both sides of the wire; 0 is a real
                // cap of zero (AI credits on Free) and must not collapse into it.
                if ($entitlements->isUnlimited($limit) || null === $value) {
                    $rendered = 'null';
                } else {
                    $rendered = (string) $value;
                }

                $lines[] = sprintf('      %s: %s,', $limit, $rendered);
            }
            $lines[] = '    },';
            $lines[] = '  },';
        }

        $lines[] = '};';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * The list price a plan is sold at. Free is 0 and Enterprise is sales-led
     * (null); the two self-serve tiers come from the `app.plan_price_*` params
     * that also pick the Stripe Price at checkout, so the page can't quote a
     * number the checkout won't honour.
     */
    private function monthlyCents(Plan $plan): ?int
    {
        return match ($plan) {
            Plan::Free => 0,
            Plan::Pro => $this->proMonthlyCents,
            Plan::Business => $this->businessMonthlyCents,
            Plan::Enterprise => null,
        };
    }

    /**
     * @param list<string> $values
     */
    private function tsConstArray(string $name, array $values): string
    {
        $quoted = array_map(static fn (string $v): string => sprintf('"%s"', $v), $values);

        return sprintf('export const %s = [%s] as const;', $name, implode(', ', $quoted));
    }
}
