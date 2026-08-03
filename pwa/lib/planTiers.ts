/**
 * The public plan matrix behind `/pricing`.
 *
 * This MIRRORS `api/src/Billing/PlanCatalog.php`, which stays the source of
 * truth for what a plan actually grants. The copy exists because the pricing
 * page is public and the entitlement API (`GET /spaces/{id}/billing`) is both
 * authenticated and per-space — a visitor who hasn't signed up has no space to
 * read entitlements from. Change the two together: the PHP decides what a
 * customer gets, this decides what we advertise, and they must not disagree.
 *
 * Prices mirror `app.plan_price_*_cents` in `api/config/services.yaml` and must
 * match the configured Stripe Prices (STRIPE_PRICE_{PRO,BUSINESS}_{MONTHLY,YEARLY}).
 * Annual bills at 10x the monthly rate, i.e. two months free.
 */

export const PLAN_KEYS = ["free", "pro", "business", "enterprise"] as const;
export type PlanKey = (typeof PLAN_KEYS)[number];

/** Feature flags, in `PlanCatalog::FEATURES` order. */
export const PLAN_FEATURES = [
  "calendar_sync",
  "time_tracking",
  "invoicing",
  "timeline",
  "automations",
  "reporting",
  "portfolios",
  "goals",
  "sso",
  "webhooks",
  "scim",
  "audit_export",
  "private_spaces",
  "guests",
  "priority_support",
  "ai_assist",
  "data_residency",
] as const;
export type PlanFeature = (typeof PLAN_FEATURES)[number];

/** Numeric limits, in `PlanCatalog::LIMITS` order. */
export const PLAN_LIMITS = [
  "space_members",
  "storage_gb",
  "history_days",
  "mcp_calls_per_day",
  "ai_credits_per_month",
] as const;
export type PlanLimit = (typeof PLAN_LIMITS)[number];

export interface PlanEntitlements {
  features: Record<PlanFeature, boolean>;
  /** null = unlimited (matching `PlanEntitlements::isUnlimited`). */
  limits: Record<PlanLimit, number | null>;
}

/**
 * Build a full feature map from a base of all-false, flipping the named flags
 * on — the same shape as `PlanCatalog::features()`, so each plan below lists
 * only what it adds and a missing flag can't silently read as granted.
 */
const featureSet = (...granted: PlanFeature[]): Record<PlanFeature, boolean> =>
  Object.fromEntries(
    PLAN_FEATURES.map((feature) => [feature, granted.includes(feature)]),
  ) as Record<PlanFeature, boolean>;

export const PLAN_ENTITLEMENTS: Record<PlanKey, PlanEntitlements> = {
  free: {
    features: featureSet("time_tracking"),
    limits: {
      space_members: 5,
      storage_gb: 2,
      history_days: 30,
      mcp_calls_per_day: 100,
      ai_credits_per_month: 0,
    },
  },
  pro: {
    features: featureSet(
      "calendar_sync",
      "time_tracking",
      "invoicing",
      "timeline",
      "guests",
    ),
    limits: {
      space_members: null,
      storage_gb: 100,
      history_days: 365,
      mcp_calls_per_day: 1000,
      ai_credits_per_month: 0,
    },
  },
  business: {
    features: featureSet(
      "calendar_sync",
      "time_tracking",
      "invoicing",
      "timeline",
      "automations",
      "reporting",
      "portfolios",
      "goals",
      "sso",
      "webhooks",
      "private_spaces",
      "guests",
      "priority_support",
      "ai_assist",
    ),
    limits: {
      space_members: null,
      storage_gb: null,
      history_days: null,
      mcp_calls_per_day: null,
      ai_credits_per_month: 2000,
    },
  },
  enterprise: {
    features: featureSet(
      "calendar_sync",
      "time_tracking",
      "invoicing",
      "timeline",
      "automations",
      "reporting",
      "portfolios",
      "goals",
      "sso",
      "webhooks",
      "scim",
      "audit_export",
      "private_spaces",
      "guests",
      "priority_support",
      "ai_assist",
      "data_residency",
    ),
    limits: {
      space_members: null,
      storage_gb: null,
      history_days: null,
      mcp_calls_per_day: null,
      ai_credits_per_month: 10000,
    },
  },
};

/* -------------------------------------------------------------------------- */
/* Pricing                                                                     */
/* -------------------------------------------------------------------------- */

/** Annual plans are billed at ten months' rate — the "2 months free" claim. */
export const ANNUAL_MONTHS_CHARGED = 10;

export interface PlanTier {
  key: PlanKey;
  name: string;
  tagline: string;
  /** Monthly list price in cents; null = sales-led / custom. */
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
    monthlyCents: 0,
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
    monthlyCents: 1000,
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
    monthlyCents: 1500,
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
    monthlyCents: null,
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
