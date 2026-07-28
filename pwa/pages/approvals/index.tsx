import Head from "next/head";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { CheckSquare } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGet, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Card } from "@/components/ui/card";
import { cn } from "@/lib/utils";

interface ApprovalRow {
  id: string;
  user: { "@id": string; name: string };
  weekStart: string | null;
  status: "pending" | "approved" | "rejected";
  submittedAt: string;
  decidedAt: string | null;
  note: string | null;
  totalSeconds: number;
}

const STATUS_BADGE: Record<ApprovalRow["status"], string> = {
  pending: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
  approved: "bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300",
  rejected: "bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300",
};

const hoursOf = (seconds: number): string => (seconds / 3600).toFixed(2);

/**
 * Timesheet approvals queue (#654) — space admins review submitted weeks:
 * approve locks the entries like billed ones; reject (with a note) unlocks
 * the week so the member can fix and re-submit.
 */
const ApprovalsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, isActiveSpaceAdmin } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();
  const [error, setError] = useState<string | null>(null);
  const [showAll, setShowAll] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceId = activeSpace?.id ?? null;

  const queueQuery = useQuery({
    queryKey: ["approvals", spaceId, showAll],
    enabled: isAuthenticated && !!spaceId && isActiveSpaceAdmin,
    queryFn: () =>
      apiGet<{ rows: ApprovalRow[] }>(
        `/spaces/${spaceId}/timesheets${showAll ? "" : "?status=pending"}`,
        { errorMessage: "Failed to load the approvals queue." },
      ),
  });
  const rows = queueQuery.data?.rows ?? [];

  const decide = useMutation({
    mutationFn: ({ id, action, note }: { id: string; action: "approve" | "reject"; note?: string }) =>
      apiSend("POST", `/timesheets/${id}/${action}`, {
        contentType: "application/json",
        errorMessage: `Failed to ${action}.`,
        body: note ? { note } : {},
      }),
    onSuccess: () => {
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ["approvals"] });
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Action failed."),
  });

  const reject = (row: ApprovalRow) => {
    const note = window.prompt("Add a note for the member (why is this rejected)?") ?? "";
    decide.mutate({ id: row.id, action: "reject", note: note.trim() || undefined });
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Approvals — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Timesheet approvals"
            icon={<CheckSquare className="size-6 text-emerald-600 dark:text-emerald-400" />}
          />

          {!isActiveSpaceAdmin ? (
            <Alert>
              <AlertDescription>
                Timesheet approvals are managed by space admins.
              </AlertDescription>
            </Alert>
          ) : (
            <>
              {error && (
                <Alert variant="destructive" className="mb-4">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <div className="mb-3 flex justify-end">
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Checkbox
                    checked={showAll}
                    onCheckedChange={(c) => setShowAll(c === true)}
                  />
                  Show decided weeks
                </label>
              </div>

              <Card>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2 font-medium">Member</th>
                        <th className="px-4 py-2 font-medium">Week of</th>
                        <th className="px-4 py-2 text-right font-medium">Hours</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2" />
                      </tr>
                    </thead>
                    <tbody>
                      {queueQuery.isLoading ? (
                        <tr>
                          <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                            Loading…
                          </td>
                        </tr>
                      ) : rows.length === 0 ? (
                        <tr>
                          <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                            {showAll
                              ? "No timesheets submitted yet."
                              : "Nothing waiting for approval."}
                          </td>
                        </tr>
                      ) : (
                        rows.map((row) => (
                          <tr key={row.id} className="border-b align-middle last:border-0">
                            <td className="px-4 py-3 font-medium">{row.user.name}</td>
                            <td className="px-4 py-3 text-muted-foreground">
                              {row.weekStart
                                ? new Date(row.weekStart).toLocaleDateString(undefined, {
                                    dateStyle: "medium",
                                  })
                                : "—"}
                            </td>
                            <td className="px-4 py-3 text-right tabular-nums">
                              {hoursOf(row.totalSeconds)}
                            </td>
                            <td className="px-4 py-3">
                              <span
                                className={cn(
                                  "rounded px-2 py-0.5 text-xs font-medium",
                                  STATUS_BADGE[row.status],
                                )}
                                title={row.note ?? undefined}
                              >
                                {row.status}
                              </span>
                              {row.note && (
                                <span className="ml-2 text-xs text-muted-foreground">
                                  {row.note}
                                </span>
                              )}
                            </td>
                            <td className="px-4 py-3 text-right">
                              {row.status === "pending" && (
                                <span className="inline-flex gap-2">
                                  <Button
                                    size="sm"
                                    onClick={() =>
                                      decide.mutate({ id: row.id, action: "approve" })
                                    }
                                    disabled={decide.isPending}
                                  >
                                    Approve
                                  </Button>
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => reject(row)}
                                    disabled={decide.isPending}
                                  >
                                    Reject
                                  </Button>
                                </span>
                              )}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </Card>
            </>
          )}
        </div>
      </div>
    </>
  );
};

export default ApprovalsPage;
