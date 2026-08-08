/**
 * GENERATED FILE — DO NOT EDIT BY HAND.
 *
 * Source of truth: api/src/Billing/PlanCatalog.php
 * Regenerate with: scripts/gen-plan-matrix.sh
 *
 * CI regenerates this and fails if the committed copy differs, so a plan
 * change on the server cannot ship a stale public pricing page.
 */

export const PLAN_KEYS = ["free", "pro", "business", "enterprise"] as const;
export type PlanKey = (typeof PLAN_KEYS)[number];

export const PLAN_FEATURES = ["calendar_sync", "time_tracking", "invoicing", "timeline", "automations", "reporting", "portfolios", "goals", "sso", "webhooks", "scim", "audit_export", "private_spaces", "guests", "priority_support", "ai_assist", "data_residency"] as const;
export type PlanFeature = (typeof PLAN_FEATURES)[number];

export const PLAN_LIMITS = ["space_members", "storage_gb", "history_days", "mcp_calls_per_day", "ai_credits_per_month"] as const;
export type PlanLimit = (typeof PLAN_LIMITS)[number];

export interface PlanEntitlements {
  features: Record<PlanFeature, boolean>;
  /** null = unlimited (matching PlanEntitlements::isUnlimited). */
  limits: Record<PlanLimit, number | null>;
}

/** Monthly list price in cents; null = sales-led / custom. */
export const PLAN_MONTHLY_CENTS: Record<PlanKey, number | null> = {
  free: 0,
  pro: 1000,
  business: 1500,
  enterprise: null,
};

export const PLAN_ENTITLEMENTS: Record<PlanKey, PlanEntitlements> = {
  free: {
    features: {
      calendar_sync: false,
      time_tracking: true,
      invoicing: false,
      timeline: false,
      automations: false,
      reporting: false,
      portfolios: false,
      goals: false,
      sso: false,
      webhooks: false,
      scim: false,
      audit_export: false,
      private_spaces: false,
      guests: false,
      priority_support: false,
      ai_assist: false,
      data_residency: false,
    },
    limits: {
      space_members: 5,
      storage_gb: 2,
      history_days: 30,
      mcp_calls_per_day: 100,
      ai_credits_per_month: 0,
    },
  },
  pro: {
    features: {
      calendar_sync: true,
      time_tracking: true,
      invoicing: true,
      timeline: true,
      automations: false,
      reporting: false,
      portfolios: false,
      goals: false,
      sso: false,
      webhooks: false,
      scim: false,
      audit_export: false,
      private_spaces: false,
      guests: true,
      priority_support: false,
      ai_assist: false,
      data_residency: false,
    },
    limits: {
      space_members: null,
      storage_gb: 100,
      history_days: 365,
      mcp_calls_per_day: 1000,
      ai_credits_per_month: 0,
    },
  },
  business: {
    features: {
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
      scim: false,
      audit_export: false,
      private_spaces: true,
      guests: true,
      priority_support: true,
      ai_assist: true,
      data_residency: false,
    },
    limits: {
      space_members: null,
      storage_gb: null,
      history_days: null,
      mcp_calls_per_day: null,
      ai_credits_per_month: 2000,
    },
  },
  enterprise: {
    features: {
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
    },
    limits: {
      space_members: null,
      storage_gb: null,
      history_days: null,
      mcp_calls_per_day: null,
      ai_credits_per_month: 10000,
    },
  },
};
