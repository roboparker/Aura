import Head from "next/head";
import dynamic from "next/dynamic";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { TrendingUp } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGet } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  FUNNEL_STAGES,
  STAGE_HINT,
  STAGE_LABEL,
  conversionRate,
  formatMoneyCents,
  stageTotal,
  type GrowthResponse,
} from "@/lib/growthTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const FunnelChart = dynamic(() => import("@/components/admin/FunnelChart"), {
  ssr: false,
  loading: () => <div className="h-[260px] animate-pulse rounded-md bg-muted/50" />,
});

const INTERVALS = [
  { value: "day", label: "Daily" },
  { value: "week", label: "Weekly" },
  { value: "month", label: "Monthly" },
] as const;

const isoDay = (d: Date): string => {
  const pad = (n: number) => n.toString().padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const weeksAgo = (weeks: number): string => {
  const d = new Date();
  d.setDate(d.getDate() - weeks * 7);
  return isoDay(d);
};

const Stat = ({
  label,
  value,
  hint,
}: {
  label: string;
  value: string;
  hint?: string;
}) => (
  <Card className="p-4">
    <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
    <p className="mt-0.5 text-2xl font-semibold tabular-nums">{value}</p>
    {hint && <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>}
  </Card>
);

/**
 * Growth dashboard (#763) — the acquisition funnel for the whole instance.
 *
 * Exists because the launch plan's 30-day targets (signups, free→paid
 * conversion, retention) were previously unmeasurable: nothing recorded when an
 * account was created. Every number here comes from our own database rather
 * than a third-party tracker.
 */
const GrowthPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  const [from, setFrom] = useState(() => weeksAgo(12));
  const [to, setTo] = useState(() => isoDay(new Date()));
  const [interval, setInterval] = useState<"day" | "week" | "month">("week");

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const params = new URLSearchParams({ from, to, interval });
  const query = useQuery({
    queryKey: ["admin_growth", from, to, interval],
    enabled: !!isAdmin,
    queryFn: () =>
      apiGet<GrowthResponse>(`/admin/growth?${params.toString()}`, {
        errorMessage: "Failed to load growth metrics.",
      }),
  });

  if (authLoading || !isAuthenticated) return null;

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>Access Denied - Madori</title>
        </Head>
        <div className="flex min-h-screen items-center justify-center bg-muted px-4">
          <div className="text-center">
            <h1 className="mb-2 text-2xl font-bold text-foreground">Access Denied</h1>
            <p className="text-muted-foreground">
              You need administrator privileges to view this page.
            </p>
          </div>
        </div>
      </>
    );
  }

  const data = query.data;
  const funnel = data?.funnel;
  const snapshot = data?.snapshot;

  const signupToPaid = conversionRate(funnel?.signups, funnel?.upgrades);
  const signupToActive = conversionRate(funnel?.signups, funnel?.activations);

  return (
    <>
      <Head>
        <title>Growth — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-5xl">
          <PageHeader
            title="Growth"
            subtitle="Signup to paid, across the whole instance. Computed from our own data — no third-party tracker."
            icon={<TrendingUp className="size-6 text-emerald-600 dark:text-emerald-400" />}
          />

          {query.isError && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>Could not load growth metrics.</AlertDescription>
            </Alert>
          )}

          {/* Current state */}
          <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Stat label="Users" value={snapshot ? String(snapshot.users) : "—"} />
            <Stat
              label="Activated"
              value={snapshot ? String(snapshot.activated) : "—"}
              hint="Created at least one task"
            />
            <Stat
              label="Paid subscriptions"
              value={snapshot ? String(snapshot.paidSubscriptions) : "—"}
              hint={snapshot ? `${snapshot.paidSeats} seats` : undefined}
            />
            <Stat
              label="Est. MRR"
              value={snapshot ? formatMoneyCents(snapshot.mrrCents) : "—"}
              hint="List prices × seats — Stripe is authoritative"
            />
          </div>

          {snapshot && snapshot.waitlisted > 0 && (
            <Alert className="mb-6">
              <AlertDescription>
                {snapshot.waitlisted} of {snapshot.users} accounts are on the
                waitlist and can&apos;t use the product yet. Conversion rates below
                will stay near zero until signups are opened.
              </AlertDescription>
            </Alert>
          )}

          {/* Range controls */}
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="gr-from">From</Label>
              <Input
                id="gr-from"
                type="date"
                value={from}
                onChange={(e) => setFrom(e.target.value)}
                className="w-40"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="gr-to">To</Label>
              <Input
                id="gr-to"
                type="date"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                className="w-40"
              />
            </div>
            <div className="inline-flex rounded-md border p-0.5">
              {INTERVALS.map((option) => (
                <Button
                  key={option.value}
                  variant={interval === option.value ? "secondary" : "ghost"}
                  size="sm"
                  onClick={() => setInterval(option.value)}
                >
                  {option.label}
                </Button>
              ))}
            </div>
          </div>

          <Card className="mb-6 p-4">
            {query.isLoading ? (
              <div className="h-[260px] animate-pulse rounded-md bg-muted/40" />
            ) : (
              <FunnelChart funnel={funnel ?? {}} />
            )}
          </Card>

          {/* Per-stage totals + the two conversion rates that matter */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {FUNNEL_STAGES.map((stage) => (
              <Stat
                key={stage}
                label={STAGE_LABEL[stage]}
                value={funnel ? String(stageTotal(funnel[stage])) : "—"}
                hint={STAGE_HINT[stage]}
              />
            ))}
          </div>

          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <Stat
              label="Signup → activated"
              value={signupToActive === null ? "—" : `${signupToActive.toFixed(1)}%`}
              hint="Target: most signups should create something"
            />
            <Stat
              label="Signup → paid"
              value={signupToPaid === null ? "—" : `${signupToPaid.toFixed(1)}%`}
              hint="Launch plan target: 3%"
            />
          </div>

          {data?.notes && (
            <p className="mt-6 text-xs text-muted-foreground">
              {Object.values(data.notes).join(" ")}
            </p>
          )}
        </div>
      </div>
    </>
  );
};

export default GrowthPage;
