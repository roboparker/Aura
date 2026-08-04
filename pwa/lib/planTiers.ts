/**
 * The public plans & pricing matrix behind `/pricing`.
 *
 * The *entitlements* — which plan grants which feature, at which limit, for how
 * much — are not defined here. They're generated from `App\Billing\PlanCatalog`
 * into `./planEntitlements.generated.ts`, and CI fails if the committed copy is
 * stale. See that file's header for why the page carries a copy at all.
 *
 * What lives here is everything the server has no opinion about: plan names,
 * taglines, card bullets, how a limit is worded, and how the comparison table
 * is grouped. Marketing copy, in other words — change it freely. To change what
 * a plan actually *grants*, edit the PHP catalog and re-run the generator.
 */

import {
  PLAN_ENTITLEMENTS,
  PLAN_FEATURES,
  PLAN_KEYS,
  PLAN_LIMITS,
  PLAN_MONTHLY_CENTS,
  type PlanFeature,
  type PlanKey,
  type PlanLimit,
} from "./planEntitlements.generated";

export {
  PLAN_ENTITLEMENTS,
  PLAN_FEATURES,
  PLAN_KEYS,
  PLAN_LIMITS,
  PLAN_MONTHLY_CENTS,
};
export type {
  PlanEntitlements,
  PlanFeature,
  PlanKey,
  PlanLimit,
} from "./planEntitlements.generated";

/* -------------------------------------------------------------------------- */
/* Pricing                                                                     */
/* -------------------------------------------------------------------------- */

/** Annual plans are billed at ten months' rate — the "2 months free" claim. */
export const ANNUAL_MONTHS_CHARGED = 10;

export interface PlanTier {
  key: PlanKey;
  name: string;
  tagline: string;
  /**
   * Monthly list price in cents; null = sales-led / custom. Read from the
   * generated matrix, so the page can't quote a price the checkout won't
   * honour — `app.plan_price_*_cents` picks the Stripe Price too.
   */
  monthlyCents: number | null;
  /** Charged per member of the account, rather than a flat account price. */
  perSeat: boolean;
  highlighted?: boolean;
  cta: { label: string; href: string };
  /** Card bullets — the headline story. The table carries the full matrix. */
  highlights: string[];
}

export const PLAN_TIERS: PlanTier[] = [
  {
    key: "free",
    name: "Free",
    tagline: "For individuals and trying things out.",
    monthlyCents: PLAN_MONTHLY_CENTS.free,
    perSeat: false,
    cta: { label: "Get started", href: "/signup" },
    highlights: [
      "Unlimited personal tasks, boards & pages",
      "Up to 5 members per shared space",
      "Time tracking & 100 API calls a day",
      "2 GB of file storage",
    ],
  },
  {
    key: "pro",
    name: "Pro",
    tagline: "For power users who want more room.",
    monthlyCents: PLAN_MONTHLY_CENTS.pro,
    perSeat: false,
    cta: { label: "Start Pro", href: "/signup" },
    highlights: [
      "Everything in Free",
      "Unlimited members in your spaces",
      "Calendar sync, timeline & invoicing",
      "100 GB storage and 10x the API limit",
    ],
  },
  {
    key: "business",
    name: "Business",
    tagline: "For teams that collaborate in shared spaces.",
    monthlyCents: PLAN_MONTHLY_CENTS.business,
    perSeat: true,
    highlighted: true,
    cta: { label: "Start Business", href: "/signup" },
    highlights: [
      "Everything in Pro",
      "Unlimited storage, history & API usage",
      "Automations, reporting, goals & portfolios",
      "SSO, private spaces & priority support",
    ],
  },
  {
    key: "enterprise",
    name: "Enterprise",
    tagline: "For organizations with advanced needs.",
    monthlyCents: PLAN_MONTHLY_CENTS.enterprise,
    perSeat: true,
    cta: { label: "Contact us", href: "/feedback" },
    highlights: [
      "Everything in Business",
      "SCIM provisioning & audit log export",
      "Data residency & custom terms",
      "Dedicated onboarding and support",
    ],
  },
];

export interface PriceLabel {
  /** Formatted amount, or "Custom" for a sales-led plan. */
  amount: string;
  /** "/seat" when charged per member, otherwise empty. */
  unit: string;
  /** "per month" / "per year" / "forever"; empty for a custom price. */
  period: string;
}

export const formatUsd = (cents: number): string => {
  const dollars = cents / 100;
  return `$${Number.isInteger(dollars) ? dollars : dollars.toFixed(2)}`;
};

export const priceFor = (tier: PlanTier, annual: boolean): PriceLabel => {
  if (tier.monthlyCents === null) return { amount: "Custom", unit: "", period: "" };
  if (tier.monthlyCents === 0) return { amount: "$0", unit: "", period: "forever" };

  const cents = annual
    ? tier.monthlyCents * ANNUAL_MONTHS_CHARGED
    : tier.monthlyCents;

  return {
    amount: formatUsd(cents),
    unit: tier.perSeat ? "/seat" : "",
    period: annual ? "per year" : "per month",
  };
};

/* -------------------------------------------------------------------------- */
/* Comparison table                                                            */
/* -------------------------------------------------------------------------- */

/** A cell: `true`/`false` renders as an icon, a string renders verbatim. */
export type ComparisonValue = boolean | string;

export interface ComparisonRow {
  key: string;
  label: string;
  hint?: string;
  values: Record<PlanKey, ComparisonValue>;
}

export interface ComparisonGroup {
  title: string;
  rows: ComparisonRow[];
}

const FEATURE_META: Record<PlanFeature, { label: string; hint?: string }> = {
  calendar_sync: {
    label: "Calendar sync",
    hint: "Two-way sync with Google Calendar and Outlook",
  },
  time_tracking: { label: "Time tracking", hint: "Timers, timesheets and expenses" },
  invoicing: { label: "Invoicing & estimates", hint: "Bill clients and take card payments" },
  timeline: { label: "Timeline (Gantt)", hint: "Dependencies and critical path" },
  automations: { label: "Board automations", hint: "When/if/then rules on a board" },
  reporting: { label: "Reporting & dashboards" },
  portfolios: { label: "Portfolios" },
  goals: { label: "Goals" },
  sso: { label: "SSO sign-in", hint: "Google and Microsoft single sign-on" },
  webhooks: { label: "Webhooks" },
  scim: { label: "SCIM provisioning" },
  audit_export: { label: "Audit log export" },
  private_spaces: { label: "Private spaces" },
  guests: { label: "Free guest accounts", hint: "Read-only collaborators, never billed" },
  priority_support: { label: "Priority support" },
  ai_assist: { label: "AI assist" },
  data_residency: { label: "Data residency" },
};

const LIMIT_META: Record<
  PlanLimit,
  { label: string; hint?: string; format: (value: number | null) => ComparisonValue }
> = {
  space_members: {
    label: "Members per space",
    format: (v) => (v === null ? "Unlimited" : `Up to ${v}`),
  },
  storage_gb: {
    label: "File storage",
    format: (v) => (v === null ? "Unlimited" : `${v} GB`),
  },
  history_days: {
    label: "Activity history",
    hint: "How far back the audit and activity feeds reach",
    format: (v) => {
      if (v === null) return "Unlimited";
      return v >= 365 ? `${Math.round(v / 365)} year` : `${v} days`;
    },
  },
  mcp_calls_per_day: {
    label: "API & MCP calls",
    hint: "Programmatic calls per user, per day. The app itself is never metered.",
    format: (v) => (v === null ? "Unlimited" : `${v.toLocaleString("en-US")} / day`),
  },
  ai_credits_per_month: {
    label: "AI credits",
    // 0 is "none", not "unknown" — render it as a plain not-included dash.
    format: (v) =>
      v === null ? "Unlimited" : v === 0 ? false : `${v.toLocaleString("en-US")} / month`,
  },
};

const featureRow = (feature: PlanFeature): ComparisonRow => ({
  key: feature,
  ...FEATURE_META[feature],
  values: Object.fromEntries(
    PLAN_KEYS.map((plan) => [plan, PLAN_ENTITLEMENTS[plan].features[feature]]),
  ) as Record<PlanKey, ComparisonValue>,
});

const limitRow = (limit: PlanLimit): ComparisonRow => ({
  key: limit,
  label: LIMIT_META[limit].label,
  hint: LIMIT_META[limit].hint,
  values: Object.fromEntries(
    PLAN_KEYS.map((plan) => [
      plan,
      LIMIT_META[limit].format(PLAN_ENTITLEMENTS[plan].limits[limit]),
    ]),
  ) as Record<PlanKey, ComparisonValue>,
});

/** A capability every plan includes — the baseline product, not a paid gate. */
const includedRow = (key: string, label: string, hint?: string): ComparisonRow => ({
  key,
  label,
  hint,
  values: Object.fromEntries(PLAN_KEYS.map((plan) => [plan, true])) as Record<
    PlanKey,
    ComparisonValue
  >,
});

export const COMPARISON_GROUPS: ComparisonGroup[] = [
  {
    title: "The workspace",
    rows: [
      includedRow("core_content", "Tasks, boards & pages"),
      includedRow("views", "List, board, calendar & search views"),
      includedRow("collaboration", "Comments, @mentions & notifications"),
      includedRow("custom_fields", "Custom fields & tags"),
      includedRow("api_access", "REST API & MCP access", "Bring your own AI agent"),
    ],
  },
  {
    title: "Limits",
    rows: PLAN_LIMITS.map(limitRow),
  },
  {
    title: "Planning & delivery",
    rows: [
      featureRow("timeline"),
      featureRow("automations"),
      featureRow("portfolios"),
      featureRow("goals"),
      featureRow("reporting"),
    ],
  },
  {
    title: "Time & billing",
    rows: [featureRow("time_tracking"), featureRow("invoicing")],
  },
  {
    title: "Integrations",
    rows: [featureRow("calendar_sync"), featureRow("webhooks"), featureRow("ai_assist")],
  },
  {
    title: "Administration & security",
    rows: [
      featureRow("private_spaces"),
      featureRow("guests"),
      featureRow("sso"),
      featureRow("scim"),
      featureRow("audit_export"),
      featureRow("data_residency"),
    ],
  },
  {
    title: "Support",
    rows: [featureRow("priority_support")],
  },
];
