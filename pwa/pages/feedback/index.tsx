import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { MessageSquare, MessagesSquare, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGetCollection } from "@/lib/apiClient";
import {
  STATUS_META,
  STATUS_ORDER,
  TYPE_META,
  TYPE_ORDER,
  formatRelative,
  type Feedback,
  type FeedbackStatus,
  type FeedbackType,
} from "@/lib/feedbackTypes";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { cn } from "@/lib/utils";

type TypeFilter = FeedbackType | "all";
type StatusFilter = FeedbackStatus | "all";
type SortKey = "votes" | "recent";

const FeedbackBoardPage = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [tickets, setTickets] = useState<Feedback[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [typeFilter, setTypeFilter] = useState<TypeFilter>("all");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("all");
  const [sort, setSort] = useState<SortKey>("votes");

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    setError(null);
    setIsLoading(true);
    try {
      const data = await apiGetCollection<Feedback>("/feedback?itemsPerPage=100", {
        errorMessage: "Failed to load feedback.",
      });
      setTickets(data);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load feedback.");
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) void load();
  }, [isAuthenticated, load]);

  const visible = useMemo(() => {
    const filtered = tickets.filter(
      (t) =>
        (typeFilter === "all" || t.type === typeFilter) &&
        (statusFilter === "all" || t.status === statusFilter),
    );
    const sorted = [...filtered];
    if (sort === "votes") {
      sorted.sort(
        (a, b) =>
          b.score - a.score ||
          new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime(),
      );
    } else {
      sorted.sort(
        (a, b) =>
          new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime(),
      );
    }
    return sorted;
  }, [tickets, typeFilter, statusFilter, sort]);

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Feedback - Madori</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-5xl mx-auto px-4 py-8 space-y-6">
          <header className="flex flex-wrap items-start justify-between gap-3">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <MessagesSquare className="h-6 w-6 text-cyan-600 dark:text-cyan-400" />
                <h1 className="text-2xl font-bold">Feedback</h1>
              </div>
              <p className="text-sm text-muted-foreground">
                Report a bug or request a feature. Vote on what matters to you —
                the team works the board from the top.
              </p>
            </div>
            <Button asChild>
              <Link href="/feedback/new" data-testid="feedback-new-link">
                <Plus className="h-4 w-4" /> New feedback
              </Link>
            </Button>
          </header>

          {/* Filters */}
          <div className="flex flex-wrap items-center gap-3">
            <SegmentGroup
              value={typeFilter}
              options={[
                { value: "all", label: "All" },
                ...TYPE_ORDER.map((t) => ({ value: t, label: TYPE_META[t].label })),
              ]}
              onChange={(v) => setTypeFilter(v as TypeFilter)}
            />
            <SegmentGroup
              value={statusFilter}
              options={[
                { value: "all", label: "All" },
                ...STATUS_ORDER.map((s) => ({ value: s, label: STATUS_META[s].label })),
              ]}
              onChange={(v) => setStatusFilter(v as StatusFilter)}
            />
            <SegmentGroup
              value={sort}
              options={[
                { value: "votes", label: "Most votes" },
                { value: "recent", label: "Most recent" },
              ]}
              onChange={(v) => setSort(v as SortKey)}
            />
          </div>

          {error && (
            <Card>
              <CardContent className="pt-6">
                <p className="text-sm text-destructive">{error}</p>
              </CardContent>
            </Card>
          )}

          {isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : visible.length === 0 ? (
            <Card>
              <CardContent className="pt-6 text-center space-y-3">
                <p className="text-muted-foreground">
                  {tickets.length === 0
                    ? "No feedback yet — be the first to file one."
                    : "Nothing matches these filters."}
                </p>
                {tickets.length === 0 && (
                  <Button asChild variant="outline">
                    <Link href="/feedback/new">
                      <Plus className="h-4 w-4" /> New feedback
                    </Link>
                  </Button>
                )}
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-2" data-testid="feedback-list">
              {visible.map((ticket) => (
                <li key={ticket["@id"]}>
                  <Card className="transition-colors hover:border-cyan-600/40">
                    <CardContent className="flex items-start gap-4 py-4">
                      <div
                        className="inline-flex min-w-10 flex-col items-center justify-center text-center"
                        data-testid="vote-score"
                      >
                        <span
                          className={cn(
                            "text-base font-semibold tabular-nums",
                            ticket.myVote === 1
                              ? "text-emerald-600 dark:text-emerald-400"
                              : ticket.myVote === -1
                                ? "text-rose-600 dark:text-rose-400"
                                : "text-foreground",
                          )}
                        >
                          {ticket.score}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {Math.abs(ticket.score) === 1 ? "vote" : "votes"}
                        </span>
                      </div>
                      <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-xs font-medium text-muted-foreground tabular-nums">
                            #{ticket.ticketNumber}
                          </span>
                          <Badge className={cn("border-0", TYPE_META[ticket.type].badgeClass)}>
                            {TYPE_META[ticket.type].label}
                          </Badge>
                          <Badge className={cn("border-0", STATUS_META[ticket.status].badgeClass)}>
                            {STATUS_META[ticket.status].label}
                          </Badge>
                        </div>
                        <Link
                          href={`/feedback/${ticket.id}`}
                          className="block font-semibold leading-tight hover:underline"
                          data-testid="feedback-row-title"
                        >
                          {ticket.title}
                        </Link>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                          <span>{formatRelative(ticket.createdAt)}</span>
                          <span className="flex items-center gap-1">
                            <MessageSquare className="h-3.5 w-3.5" />
                            {ticket.commentCount}
                          </span>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </>
  );
};

const SegmentGroup = ({
  value,
  options,
  onChange,
}: {
  value: string;
  options: { value: string; label: string }[];
  onChange: (value: string) => void;
}) => (
  <div className="inline-flex items-center divide-x divide-input overflow-hidden rounded-full border border-input">
    {options.map((o) => {
      const active = o.value === value;
      return (
        <button
          key={o.value}
          type="button"
          onClick={() => onChange(o.value)}
          aria-pressed={active}
          className={cn(
            "px-3 py-1.5 text-xs font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring",
            active
              ? "bg-cyan-700 text-white hover:bg-cyan-800"
              : "bg-background text-muted-foreground hover:bg-accent",
          )}
        >
          {o.label}
        </button>
      );
    })}
  </div>
);

export default FeedbackBoardPage;
