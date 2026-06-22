import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { MessageSquare, MessagesSquare, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { displayName } from "@/lib/userDisplay";
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
import VoteControl from "@/components/feedback/VoteControl";
import UserAvatar from "@/components/user/UserAvatar";
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

  const handleVote = useCallback(
    async (ticket: Feedback, value: 1 | -1) => {
      // Optimistic isn't required — the endpoint returns the authoritative
      // score + myVote, so we just patch the row from the response.
      try {
        const res = await apiSend<{ score: number; myVote: number }>(
          "POST",
          `/feedback/${ticket.id}/vote`,
          { body: { value }, errorMessage: "Failed to vote." },
        );
        if (!res) return;
        setTickets((prev) =>
          prev.map((t) =>
            t["@id"] === ticket["@id"]
              ? { ...t, score: res.score, myVote: res.myVote }
              : t,
          ),
        );
      } catch (err) {
        setError(err instanceof Error ? err.message : "Failed to vote.");
      }
    },
    [],
  );

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
          <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
            <FilterRow label="Type">
              <FilterPill
                active={typeFilter === "all"}
                onClick={() => setTypeFilter("all")}
              >
                All
              </FilterPill>
              {TYPE_ORDER.map((t) => (
                <FilterPill
                  key={t}
                  active={typeFilter === t}
                  onClick={() => setTypeFilter(t)}
                >
                  {TYPE_META[t].label}
                </FilterPill>
              ))}
            </FilterRow>

            <FilterRow label="Status">
              <FilterPill
                active={statusFilter === "all"}
                onClick={() => setStatusFilter("all")}
              >
                All
              </FilterPill>
              {STATUS_ORDER.map((s) => (
                <FilterPill
                  key={s}
                  active={statusFilter === s}
                  onClick={() => setStatusFilter(s)}
                >
                  {STATUS_META[s].label}
                </FilterPill>
              ))}
            </FilterRow>

            <FilterRow label="Sort">
              <FilterPill active={sort === "votes"} onClick={() => setSort("votes")}>
                Most votes
              </FilterPill>
              <FilterPill active={sort === "recent"} onClick={() => setSort("recent")}>
                Most recent
              </FilterPill>
            </FilterRow>
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
                      <VoteControl
                        score={ticket.score}
                        myVote={ticket.myVote}
                        onVote={(v) => void handleVote(ticket, v)}
                      />
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
                          <span className="flex items-center gap-1.5">
                            <UserAvatar user={ticket.owner} size="sm" />
                            {displayName(ticket.owner)}
                          </span>
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

const FilterRow = ({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) => (
  <div className="flex items-center gap-2">
    <span className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
      {label}
    </span>
    <div className="flex flex-wrap items-center gap-1">{children}</div>
  </div>
);

const FilterPill = ({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) => (
  <button
    type="button"
    onClick={onClick}
    className={cn(
      "rounded-full px-3 py-1 text-xs font-medium transition-colors",
      active
        ? "bg-cyan-700 text-white"
        : "bg-background text-muted-foreground hover:bg-accent border border-input",
    )}
  >
    {children}
  </button>
);

export default FeedbackBoardPage;
