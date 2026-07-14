import { useState } from "react";
import Head from "next/head";
import Link from "next/link";
import { Check } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * Public pricing page.
 *
 * Prices: Pro $10/mo (flat), Business $15/mo per seat; annual = ×10 (2 months
 * free). These must match the Stripe Prices
 * (STRIPE_PRICE_{PRO,BUSINESS}_{MONTHLY,YEARLY}) — update both together. Free-tier
 * limits mirror the app.free_* backend defaults (member cap 5, 100 MCP calls/day).
 */

interface Tier {
  name: string;
  tagline: string;
  /** Monthly price in whole currency units; null = custom / contact us. */
  monthly: number | null;
  /** Whether the price is charged per seat (vs a flat account price). */
  perSeat?: boolean;
  cta: { label: string; href: string };
  highlighted?: boolean;
  features: string[];
}

const TIERS: Tier[] = [
  {
    name: "Free",
    tagline: "For individuals and trying things out.",
    monthly: 0,
    cta: { label: "Get started", href: "/signup" },
    features: [
      "Unlimited personal tasks, projects & pages",
      "Up to 5 members per shared space",
      "100 MCP (programmatic API) calls per day",
      "Calendar, discussions & file attachments",
    ],
  },
  {
    name: "Pro",
    tagline: "For power users who want more room.",
    monthly: 10,
    cta: { label: "Start Pro", href: "/signup" },
    features: [
      "Everything in Free",
      "Higher MCP / API limits",
      "Calendar sync (Google & Microsoft)",
      "Priority email support",
    ],
  },
  {
    name: "Business",
    tagline: "For teams that collaborate in shared spaces.",
    monthly: 15,
    perSeat: true,
    highlighted: true,
    cta: { label: "Start Business", href: "/signup" },
    features: [
      "Everything in Pro",
      "Unlimited members & unlimited MCP usage",
      "Custom roles & per-space permissions",
      "SSO sign-in, automations & AI assist",
    ],
  },
  {
    name: "Enterprise",
    tagline: "For organizations with advanced needs.",
    monthly: null,
    cta: { label: "Contact us", href: "/feedback" },
    features: [
      "Everything in Business",
      "SCIM provisioning & advanced controls",
      "Dedicated onboarding & support",
      "Custom terms & invoicing",
    ],
  },
];

const Pricing = () => {
  const [annual, setAnnual] = useState(false);

  const priceLabel = (tier: Tier): { amount: string; period: string } => {
    if (tier.monthly === null) return { amount: "Custom", period: "" };
    if (tier.monthly === 0) return { amount: "$0", period: "forever" };
    // Annual billed at 10× the monthly rate → two months free.
    const amount = annual ? tier.monthly * 10 : tier.monthly;
    const unit = tier.perSeat ? "/seat" : "";
    return {
      amount: `$${amount}${unit}`,
      period: annual ? "per year" : "per month",
    };
  };

  return (
    <>
      <Head>
        <title>Pricing — Madori</title>
        <meta
          name="description"
          content="Madori pricing — a free plan for individuals, plus Pro, Business, and Enterprise tiers for teams."
        />
      </Head>

      <main className="bg-background">
        <div className="max-w-6xl mx-auto px-4 py-12 md:py-16">
          <header className="text-center mb-10">
            <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground mb-3">
              Simple, transparent pricing
            </h1>
            <p className="text-muted-foreground max-w-2xl mx-auto">
              Start free and upgrade when your team grows. Every plan includes
              the full Madori workspace — tasks, projects, pages, calendar, and
              programmatic API access.
            </p>
          </header>

          {/* Billing period toggle */}
          <div className="flex items-center justify-center gap-1 mb-10">
            <div className="inline-flex rounded-lg border border-input p-1 bg-card">
              <button
                type="button"
                onClick={() => setAnnual(false)}
                className={cn(
                  "px-4 py-1.5 text-sm font-medium rounded-md transition-colors",
                  !annual
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:text-foreground",
                )}
                aria-pressed={!annual}
              >
                Monthly
              </button>
              <button
                type="button"
                onClick={() => setAnnual(true)}
                className={cn(
                  "px-4 py-1.5 text-sm font-medium rounded-md transition-colors",
                  annual
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:text-foreground",
                )}
                aria-pressed={annual}
              >
                Annual
                <span className="ml-1.5 text-xs text-emerald-600">2 months free</span>
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            {TIERS.map((tier) => {
              const { amount, period } = priceLabel(tier);
              return (
                <div
                  key={tier.name}
                  className={cn(
                    "flex flex-col rounded-xl border bg-card p-6 shadow-sm",
                    tier.highlighted
                      ? "border-primary ring-1 ring-primary"
                      : "border-border",
                  )}
                >
                  {tier.highlighted ? (
                    <span className="mb-3 inline-flex w-fit rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                      Most popular
                    </span>
                  ) : null}
                  <h2 className="text-lg font-semibold text-foreground">{tier.name}</h2>
                  <p className="mt-1 text-sm text-muted-foreground min-h-10">{tier.tagline}</p>
                  <div className="mt-4 flex items-baseline gap-1">
                    <span className="text-3xl font-bold text-foreground">{amount}</span>
                    {period ? (
                      <span className="text-sm text-muted-foreground">{period}</span>
                    ) : null}
                  </div>
                  <Button
                    asChild
                    variant={tier.highlighted ? "default" : "outline"}
                    className="mt-5 w-full"
                  >
                    <Link href={tier.cta.href}>{tier.cta.label}</Link>
                  </Button>
                  <ul className="mt-6 space-y-2.5 text-sm">
                    {tier.features.map((feature) => (
                      <li key={feature} className="flex items-start gap-2">
                        <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                        <span className="text-muted-foreground">{feature}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </div>

          <p className="mt-10 text-center text-sm text-muted-foreground">
            Prices in USD. Business is billed per seat. Questions?{" "}
            <Link href="/faq" className="underline underline-offset-4 hover:text-foreground">
              Read the FAQ
            </Link>{" "}
            or{" "}
            <Link href="/feedback" className="underline underline-offset-4 hover:text-foreground">
              get in touch
            </Link>
            .
          </p>
        </div>
      </main>
    </>
  );
};

export default Pricing;
