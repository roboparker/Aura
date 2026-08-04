<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\PlanMatrixExporter;
use PHPUnit\Framework\TestCase;

/**
 * The exporter feeds a CI drift check (`scripts/gen-plan-matrix.sh` + a
 * `git diff` gate), which is only as good as its output. These pin the two
 * properties that gate depends on: the output is byte-stable across runs, and
 * it actually carries every plan / feature / limit the catalog defines — an
 * exporter that silently dropped a row would let real drift through while the
 * diff stayed clean.
 */
final class PlanMatrixExporterTest extends TestCase
{
    private const FREE_MEMBERS = 5;
    private const FREE_MCP = 100;
    private const PRO_CENTS = 1000;
    private const BUSINESS_CENTS = 1500;

    private function exporter(): PlanMatrixExporter
    {
        return new PlanMatrixExporter(
            new PlanCatalog(self::FREE_MEMBERS, self::FREE_MCP),
            self::PRO_CENTS,
            self::BUSINESS_CENTS,
        );
    }

    public function testOutputIsByteStableAcrossRuns(): void
    {
        // The CI gate compares the regenerated file with the committed one, so
        // any run-to-run instability (hash ordering, timestamps) would fail
        // every PR regardless of whether anything actually drifted.
        $this->assertSame($this->exporter()->export(), $this->exporter()->export());
    }

    public function testCarriesEveryPlanFeatureAndLimit(): void
    {
        $ts = $this->exporter()->export();

        foreach (Plan::cases() as $plan) {
            $this->assertStringContainsString(
                sprintf('  %s: {', $plan->value),
                $ts,
                sprintf('plan "%s" missing from the export', $plan->value),
            );
        }

        foreach (PlanCatalog::FEATURES as $feature) {
            $this->assertStringContainsString(
                sprintf('      %s: ', $feature),
                $ts,
                sprintf('feature "%s" missing from the export', $feature),
            );
        }

        foreach (PlanCatalog::LIMITS as $limit) {
            $this->assertStringContainsString(
                sprintf('      %s: ', $limit),
                $ts,
                sprintf('limit "%s" missing from the export', $limit),
            );
        }
    }

    public function testRendersGrantedAndDeniedFeaturesDistinctly(): void
    {
        $ts = $this->exporter()->export();

        // Free gets time tracking but not SSO. If both rendered the same the
        // page would advertise a uniform matrix and the diff gate would never
        // notice a real change.
        $free = $this->planBlock($ts, Plan::Free);
        $this->assertStringContainsString('time_tracking: true,', $free);
        $this->assertStringContainsString('sso: false,', $free);
    }

    public function testRendersUnlimitedAsNullAndCapsAsNumbers(): void
    {
        $ts = $this->exporter()->export();

        $free = $this->planBlock($ts, Plan::Free);
        $this->assertStringContainsString('space_members: ' . self::FREE_MEMBERS . ',', $free);
        $this->assertStringContainsString('mcp_calls_per_day: ' . self::FREE_MCP . ',', $free);

        // Unlimited must be `null`, not `0` — the PWA distinguishes the two, and
        // 0 would render as a hard cap of zero.
        $business = $this->planBlock($ts, Plan::Business);
        $this->assertStringContainsString('space_members: null,', $business);
        $this->assertStringContainsString('storage_gb: null,', $business);
    }

    public function testQuotesPricesFromTheConfiguredParameters(): void
    {
        // The same parameters pick the Stripe Price at checkout, so a page that
        // quoted something else would advertise a price we don't charge.
        $ts = $this->exporter()->export();

        $this->assertStringContainsString('  free: 0,', $ts);
        $this->assertStringContainsString('  pro: ' . self::PRO_CENTS . ',', $ts);
        $this->assertStringContainsString('  business: ' . self::BUSINESS_CENTS . ',', $ts);
        // Enterprise is sales-led — no self-serve price to quote.
        $this->assertStringContainsString('  enterprise: null,', $ts);
    }

    public function testMarksTheFileAsGeneratedAndSaysHowToRegenerateIt(): void
    {
        $ts = $this->exporter()->export();

        $this->assertStringContainsString('DO NOT EDIT', $ts);
        $this->assertStringContainsString(PlanMatrixExporter::REGENERATE_HINT, $ts);
    }

    /** The slice of the export between one plan's opening brace and the next. */
    private function planBlock(string $ts, Plan $plan): string
    {
        $start = strpos($ts, sprintf('  %s: {', $plan->value));
        $this->assertNotFalse($start, sprintf('plan "%s" not in export', $plan->value));

        $end = strpos($ts, "\n  },\n", $start);
        $this->assertNotFalse($end);

        return substr($ts, $start, $end - $start);
    }
}
