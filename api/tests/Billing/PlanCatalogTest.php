<?php

namespace App\Tests\Billing;

use App\Billing\Plan;
use App\Billing\PlanCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The membership tier matrix (#billing Phase 0): plan resolution + per-plan
 * entitlements.
 */
class PlanCatalogTest extends TestCase
{
    private function catalog(): PlanCatalog
    {
        // Free caps injected from the app.free_* params (5 members, 100 MCP/day).
        return new PlanCatalog(5, 100);
    }

    public function testFromStorageMapsLegacyAndUnknown(): void
    {
        $this->assertSame(Plan::Pro, Plan::fromStorage('pro'));
        $this->assertSame(Plan::Business, Plan::fromStorage('business'));
        // Legacy single paid plan → Business.
        $this->assertSame(Plan::Business, Plan::fromStorage('team'));
        $this->assertSame(Plan::Enterprise, Plan::fromStorage('enterprise'));
        $this->assertSame(Plan::Free, Plan::fromStorage(null));
        $this->assertSame(Plan::Free, Plan::fromStorage('mystery'));
    }

    public function testRankMaxAndPaid(): void
    {
        $this->assertSame(Plan::Business, Plan::Free->max(Plan::Business));
        $this->assertSame(Plan::Enterprise, Plan::Enterprise->max(Plan::Pro));
        $this->assertFalse(Plan::Free->isPaid());
        $this->assertTrue(Plan::Pro->isPaid());
        $this->assertGreaterThan(Plan::Pro->rank(), Plan::Business->rank());
    }

    public function testFreePlanUsesInjectedCapsAndLocksPaidFeatures(): void
    {
        $free = $this->catalog()->for(Plan::Free);

        $this->assertSame(5, $free->limit('space_members'));
        $this->assertSame(100, $free->limit('mcp_calls_per_day'));
        $this->assertFalse($free->can('calendar_sync'));
        $this->assertFalse($free->can('automations'));
        $this->assertTrue($free->can('time_tracking'));
        // Unknown limit key fails closed at 0.
        $this->assertSame(0, $free->limit('nonexistent'));
    }

    public function testProLiftsCapsAndUnlocksCore(): void
    {
        $pro = $this->catalog()->for(Plan::Pro);

        $this->assertNull($pro->limit('space_members'), 'unlimited members');
        $this->assertTrue($pro->isUnlimited('space_members'));
        $this->assertTrue($pro->can('calendar_sync'));
        $this->assertTrue($pro->can('invoicing'));
        $this->assertTrue($pro->can('timeline'));
        $this->assertTrue($pro->can('guests'));
        // Business-tier features still locked on Pro.
        $this->assertFalse($pro->can('automations'));
        $this->assertFalse($pro->can('sso'));
        $this->assertFalse($pro->can('ai_assist'));
    }

    public function testBusinessUnlocksReportingAndAi(): void
    {
        $business = $this->catalog()->for(Plan::Business);

        $this->assertTrue($business->can('automations'));
        $this->assertTrue($business->can('reporting'));
        $this->assertTrue($business->can('portfolios'));
        $this->assertTrue($business->can('sso'));
        $this->assertTrue($business->can('webhooks'));
        $this->assertTrue($business->can('ai_assist'));
        $this->assertSame(2000, $business->limit('ai_credits_per_month'));
        $this->assertNull($business->limit('mcp_calls_per_day'));
        // Enterprise-only features still locked.
        $this->assertFalse($business->can('scim'));
        $this->assertFalse($business->can('audit_export'));
    }

    public function testEnterpriseUnlocksScimAndAudit(): void
    {
        $enterprise = $this->catalog()->for(Plan::Enterprise);

        $this->assertTrue($enterprise->can('scim'));
        $this->assertTrue($enterprise->can('audit_export'));
        $this->assertTrue($enterprise->can('data_residency'));
        $this->assertNull($enterprise->limit('storage_gb'));
    }

    public function testToArrayShape(): void
    {
        $array = $this->catalog()->for(Plan::Pro)->toArray();

        $this->assertSame('pro', $array['plan']);
        $this->assertTrue($array['features']['calendar_sync']);
        $this->assertNull($array['limits']['space_members']);
    }
}
