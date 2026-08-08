import { useState } from "react";
import Head from "next/head";
import Link from "next/link";
import { Check, Minus } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  Table,
  TableBody,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import { pageTitle } from "@/lib/pageTitle";
import {
  COMPARISON_GROUPS,
  PLAN_KEYS,
  PLAN_TIERS,
  priceFor,
  type ComparisonValue,
  type PlanKey,
  type PlanTier,
} from "@/lib/planTiers";

/**
 * Public plans & pricing page.
 *
 * Both the cards and the comparison table read from `@/lib/planTiers`, which
 * mirrors `App\Billing\PlanCatalog` — so what we advertise here can't drift
 * from what the server actually grants. Edit the matrix, not this file, to
 * change a plan's contents.
 */

const TIER_BY_KEY = new Map<PlanKey, PlanTier>(
  PLAN_TIERS.map((tier) => [tier.key, tier]),
);

/**
 * Render one comparison cell. Booleans become icons carrying an `sr-only`
 * word, because a bare ✓/✗ glyph tells a screen-reader user nothing about
 * which plan column it sits in once the row label is read separately.
 */
const ValueCell = ({ value }: { value: ComparisonValue }) => {
  if (typeof value === "string") {
    return <span className="text-sm text-foreground">{value}</span>;
  }

  return value ? (
    <>
      <Check aria-hidden="true" className="mx-auto h-4 w-4 text-emerald-600" />
      <span className="sr-only">Included</span>
    </>
  ) : (
    <>
      <Minus aria-hidden="true" className="mx-auto h-4 w-4 text-muted-foreground/50" />
      <span className="sr-only">Not included</span>
    </>
  );
};

const Pricing = () => {
  const [annual, setAnnual] = useState(false);

  return (
    <>
      <Head>
        <title>{pageTitle("Plans & pricing")}</title>
        <meta
          name="description"
          content="Madori plans and pricing — a free plan for individuals, plus Pro, Business, and Enterprise tiers for teams. Compare features, limits, and costs side by side."
        />
      </Head>

      <div className="bg-background">
        <div className="max-w-6xl mx-auto px-4 py-12 md:py-16">
          <header className="text-center mb-10">
            <h1 className="text-3xl md:text-4xl font-bold tracking-tight text-foreground mb-3">
              Plans &amp; pricing
            </h1>
            <p className="text-muted-foreground max-w-2xl mx-auto">
              Start free and upgrade when your team grows. Every plan includes
              the full Madori workspace — tasks, boards, pages, calendar, and
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
                <span
                  className={cn(
                    "ml-1.5 text-xs",
                    annual ? "text-primary-foreground/80" : "text-emerald-600",
                  )}
                >
                  2 months free
                </span>
              </button>
            </div>
          </div>

          <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            {PLAN_TIERS.map((tier) => {
              const { amount, unit, period } = priceFor(tier, annual);
              return (
                <div
                  key={tier.key}
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
                  <p className="mt-1 text-sm text-muted-foreground min-h-10">
                    {tier.tagline}
                  </p>
                  <div className="mt-4 flex items-baseline gap-1">
                    <span className="text-3xl font-bold text-foreground">
                      {amount}
                      {unit ? (
                        <span className="text-base font-medium text-muted-foreground">
                          {unit}
                        </span>
                      ) : null}
                    </span>
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
                    {tier.highlights.map((feature) => (
                      <li key={feature} className="flex items-start gap-2">
                        <Check
                          aria-hidden="true"
                          className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"
                        />
                        <span className="text-muted-foreground">{feature}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </div>

          {/* Full comparison */}
          <section className="mt-16" aria-labelledby="compare-heading">
            <h2
              id="compare-heading"
              className="text-2xl font-bold tracking-tight text-foreground text-center"
            >
              Compare every plan
            </h2>
            <p className="mt-2 mb-8 text-center text-sm text-muted-foreground">
              Everything each plan includes, side by side.
            </p>

            <div className="rounded-xl border border-border bg-card">
              <Table
                scrollRegionLabel="Plan comparison"
                className="min-w-[46rem] border-separate border-spacing-0"
              >
                <TableHeader>
                  <TableRow className="hover:bg-transparent">
                    {/* Sticky so the row label stays put while the plan
                        columns scroll sideways on a narrow screen. */}
                    <TableHead className="sticky left-0 z-10 bg-card w-[38%] px-4 normal-case tracking-normal text-sm font-semibold text-foreground">
                      Feature
                    </TableHead>
                    {PLAN_KEYS.map((key) => {
                      const tier = TIER_BY_KEY.get(key);
                      if (!tier) return null;
                      const { amount, unit, period } = priceFor(tier, annual);
                      return (
                        <TableHead
                          key={key}
                          scope="col"
                          className={cn(
                            "px-4 py-3 text-center normal-case tracking-normal align-top",
                            tier.highlighted && "bg-primary/5",
                          )}
                        >
                          <span className="block text-sm font-semibold text-foreground">
                            {tier.name}
                          </span>
                          <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                            {amount}
                            {unit}
                            {period ? ` ${period}` : ""}
                          </span>
                        </TableHead>
                      );
                    })}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {/* flatMap, not a fragment per group: the rows must stay
                      direct <tbody> children for the Table primitive's
                      last-child border rules to apply. */}
                  {COMPARISON_GROUPS.flatMap((group) => [
                    <TableRow key={`${group.title}-header`} className="hover:bg-transparent">
                      <th
                        scope="colgroup"
                        colSpan={PLAN_KEYS.length + 1}
                        className="sticky left-0 bg-muted/50 px-4 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground"
                      >
                        {group.title}
                      </th>
                    </TableRow>,
                    ...group.rows.map((row) => (
                      <TableRow key={`${group.title}-${row.key}`}>
                        <th
                          scope="row"
                          className="sticky left-0 z-10 bg-card px-4 py-2.5 text-left align-middle font-normal"
                        >
                          <span className="block text-sm text-foreground">{row.label}</span>
                          {row.hint ? (
                            <span className="block text-xs text-muted-foreground">
                              {row.hint}
                            </span>
                          ) : null}
                        </th>
                        {PLAN_KEYS.map((key) => (
                          <td
                            key={key}
                            className={cn(
                              "px-4 py-2.5 text-center align-middle",
                              TIER_BY_KEY.get(key)?.highlighted && "bg-primary/5",
                            )}
                          >
                            <ValueCell value={row.values[key]} />
                          </td>
                        ))}
                      </TableRow>
                    )),
                  ])}
                </TableBody>
              </Table>
            </div>
          </section>

          <p className="mt-10 text-center text-sm text-muted-foreground">
            Prices in USD, excluding any applicable tax. Business and Enterprise
            are billed per seat; guests are always free. Questions?{" "}
            <Link href="/faq" className="underline underline-offset-4 hover:text-foreground">
              Read the FAQ
            </Link>{" "}
            or{" "}
            <Link
              href="/feedback"
              className="underline underline-offset-4 hover:text-foreground"
            >
              get in touch
            </Link>
            .
          </p>
        </div>
      </div>
    </>
  );
};

export default Pricing;
