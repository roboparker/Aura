<?php

declare(strict_types=1);

namespace App\Billing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The single source of truth for what each {@see Plan} unlocks (#billing
 * Phase 0). Feature gates and limits everywhere resolve through here, so the
 * tier matrix lives in one place and the eventual Organization move only
 * changes *how the plan is resolved*, never what a plan grants.
 *
 * The Free tier's member + MCP limits are injected from the existing
 * `app.free_*` parameters so the freemium caps keep one authoritative value.
 * A `null` limit means unlimited.
 */
final class PlanCatalog
{
    /** Feature flags a plan can grant. */
    public const FEATURES = [
        'calendar_sync', 'time_tracking', 'invoicing', 'timeline', 'automations',
        'reporting', 'portfolios', 'goals', 'sso', 'webhooks', 'scim',
        'audit_export', 'private_spaces', 'guests', 'priority_support', 'ai_assist',
        'data_residency',
    ];

    /** Numeric limits a plan can set (null = unlimited). */
    public const LIMITS = [
        'space_members', 'storage_gb', 'history_days', 'mcp_calls_per_day', 'ai_credits_per_month',
    ];

    public function __construct(
        #[Autowire('%app.free_space_member_limit%')]
        private readonly int $freeSpaceMemberLimit,
        #[Autowire('%app.free_mcp_daily_limit%')]
        private readonly int $freeMcpDailyLimit,
    ) {
    }

    public function for(Plan $plan): PlanEntitlements
    {
        return match ($plan) {
            Plan::Free => $this->free(),
            Plan::Pro => $this->pro(),
            Plan::Business => $this->business(),
            Plan::Enterprise => $this->enterprise(),
        };
    }

    private function free(): PlanEntitlements
    {
        return new PlanEntitlements(
            Plan::Free,
            $this->features(time_tracking: true),
            [
                'space_members' => $this->freeSpaceMemberLimit,
                'storage_gb' => 2,
                'history_days' => 30,
                'mcp_calls_per_day' => $this->freeMcpDailyLimit,
                'ai_credits_per_month' => 0,
            ],
        );
    }

    private function pro(): PlanEntitlements
    {
        return new PlanEntitlements(
            Plan::Pro,
            $this->features(
                calendar_sync: true,
                time_tracking: true,
                invoicing: true,
                timeline: true,
                guests: true,
            ),
            [
                'space_members' => null,
                'storage_gb' => 100,
                'history_days' => 365,
                'mcp_calls_per_day' => 1000,
                'ai_credits_per_month' => 0,
            ],
        );
    }

    private function business(): PlanEntitlements
    {
        return new PlanEntitlements(
            Plan::Business,
            $this->features(
                calendar_sync: true,
                time_tracking: true,
                invoicing: true,
                timeline: true,
                automations: true,
                reporting: true,
                portfolios: true,
                goals: true,
                sso: true,
                webhooks: true,
                private_spaces: true,
                guests: true,
                priority_support: true,
                ai_assist: true,
            ),
            [
                'space_members' => null,
                'storage_gb' => null,
                'history_days' => null,
                'mcp_calls_per_day' => null,
                'ai_credits_per_month' => 2000,
            ],
        );
    }

    private function enterprise(): PlanEntitlements
    {
        return new PlanEntitlements(
            Plan::Enterprise,
            $this->features(
                calendar_sync: true,
                time_tracking: true,
                invoicing: true,
                timeline: true,
                automations: true,
                reporting: true,
                portfolios: true,
                goals: true,
                sso: true,
                webhooks: true,
                scim: true,
                audit_export: true,
                private_spaces: true,
                guests: true,
                priority_support: true,
                ai_assist: true,
                data_residency: true,
            ),
            [
                'space_members' => null,
                'storage_gb' => null,
                'history_days' => null,
                'mcp_calls_per_day' => null,
                'ai_credits_per_month' => 10000,
            ],
        );
    }

    /**
     * Build the full feature map from a base of all-false, flipping the named
     * flags on. Keeps each plan definition to just the features it adds.
     *
     * @return array<string, bool>
     */
    private function features(
        bool $calendar_sync = false,
        bool $time_tracking = false,
        bool $invoicing = false,
        bool $timeline = false,
        bool $automations = false,
        bool $reporting = false,
        bool $portfolios = false,
        bool $goals = false,
        bool $sso = false,
        bool $webhooks = false,
        bool $scim = false,
        bool $audit_export = false,
        bool $private_spaces = false,
        bool $guests = false,
        bool $priority_support = false,
        bool $ai_assist = false,
        bool $data_residency = false,
    ): array {
        return [
            'calendar_sync' => $calendar_sync,
            'time_tracking' => $time_tracking,
            'invoicing' => $invoicing,
            'timeline' => $timeline,
            'automations' => $automations,
            'reporting' => $reporting,
            'portfolios' => $portfolios,
            'goals' => $goals,
            'sso' => $sso,
            'webhooks' => $webhooks,
            'scim' => $scim,
            'audit_export' => $audit_export,
            'private_spaces' => $private_spaces,
            'guests' => $guests,
            'priority_support' => $priority_support,
            'ai_assist' => $ai_assist,
            'data_residency' => $data_residency,
        ];
    }
}
