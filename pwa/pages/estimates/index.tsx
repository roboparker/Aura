import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileSpreadsheet, MoreHorizontal, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { formatMoney } from "@/lib/invoiceTypes";
import { Estimate, ESTIMATE_STATUS_META, estimateClientName } from "@/lib/estimateTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { pageTitle } from "@/lib/pageTitle";

interface ClientLite {
  "@id": string;
  name: string;
  currency?: string | null;
}

/**
 * Estimates (#652): quotes with a draft → sent → accepted/declined
 * lifecycle. Rows link into the detail editor; accepted estimates convert
 * into draft invoices.
 */
const EstimatesPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [newClient, setNewClient] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
  const canManage = can("invoices", "create");

  const estimatesQuery = useQuery({
    queryKey: ["estimates", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Estimate>(
        spaceIri ? `/estimates?space=${encodeURIComponent(spaceIri)}` : "/estimates",
        { errorMessage: "Failed to load estimates." },
      ),
  });
  const clientsQuery = useQuery({
    queryKey: ["clients", spaceIri],
    enabled: isAuthenticated && canManage,
    queryFn: () =>
      apiGetCollection<ClientLite>(
        spaceIri ? `/clients?space=${encodeURIComponent(spaceIri)}` : "/clients",
        { errorMessage: "Failed to load clients." },
      ),
  });
  const estimates = estimatesQuery.data ?? [];
  const clients = clientsQuery.data ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["estimates"] });

  const create = useMutation({
    mutationFn: () => {
      const clientRow = clients.find((c) => c["@id"] === newClient);
      return apiSend<Estimate>("POST", "/estimates", {
        errorMessage: "Failed to create the estimate.",
        body: {
          space: spaceIri,
          client: newClient,
          currency: clientRow?.currency ?? "USD",
          lineItems: [],
        },
      });
    },
    onSuccess: (res) => {
      setError(null);
      setShowComposer(false);
      setNewClient("");
      if (res?.id) void router.push(`/estimates/${res.id}`);
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to create the estimate."),
  });

  const act = useMutation({
    mutationFn: ({ estimate, action }: { estimate: Estimate; action: "send" | "convert" }) =>
      apiSend<{ token?: string; invoice?: { id: string } }>(
        "POST",
        `${estimate["@id"]}/${action}`,
        {
          contentType: "application/json",
          errorMessage: `Failed to ${action} the estimate.`,
          body: {},
        },
      ),
    onSuccess: (res, { action }) => {
      setError(null);
      if (action === "send" && res?.token) {
        setNotice(`Estimate sent. Shareable link: ${window.location.origin}/e/${res.token}`);
      } else if (action === "convert" && res?.invoice?.id) {
        setNotice("Converted to a draft invoice.");
        void router.push(`/invoices/${res.invoice.id}`);
      }
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Action failed."),
  });

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
        <title>{pageTitle("Estimates")}</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Estimates"
            icon={<FileSpreadsheet className="size-6 text-teal-600 dark:text-teal-400" />}
          />

          {notice && (
            <Alert className="mb-4">
              <AlertDescription>{notice}</AlertDescription>
            </Alert>
          )}
          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {estimatesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Estimate</th>
                      <th className="px-4 py-2 font-medium">Client</th>
                      <th className="px-4 py-2 text-right font-medium">Total</th>
                      <th className="px-4 py-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {canManage &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={4} className="px-4 py-4">
                            <div className="flex flex-wrap items-end gap-3">
                              <div className="space-y-1.5">
                                <Label htmlFor="est-client">New estimate for</Label>
                                <select
                                  id="est-client"
                                  value={newClient}
                                  onChange={(e) => setNewClient(e.target.value)}
                                  className="h-9 w-64 max-w-full rounded-md border border-input bg-background px-3 text-sm"
                                >
                                  <option value="">Select a client…</option>
                                  {clients.map((c) => (
                                    <option key={c["@id"]} value={c["@id"]}>
                                      {c.name}
                                    </option>
                                  ))}
                                </select>
                              </div>
                              <Button
                                size="sm"
                                onClick={() => create.mutate()}
                                disabled={!newClient || create.isPending}
                              >
                                {create.isPending ? "Creating…" : "Create draft"}
                              </Button>
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setShowComposer(false)}
                              >
                                Cancel
                              </Button>
                              {clients.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                  Add a{" "}
                                  <Link href="/clients" className="text-primary hover:underline">
                                    client
                                  </Link>{" "}
                                  first.
                                </p>
                              )}
                            </div>
                          </td>
                        </tr>
                      ) : (
                        <tr className="border-b">
                          <td colSpan={4} className="px-4 py-2">
                            <button
                              type="button"
                              onClick={() => setShowComposer(true)}
                              className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                            >
                              <Plus className="h-4 w-4" /> New estimate
                            </button>
                          </td>
                        </tr>
                      ))}

                    {estimates.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="px-4 py-10 text-center text-muted-foreground">
                          No estimates yet.
                        </td>
                      </tr>
                    ) : (
                      estimates.map((estimate) => {
                        const meta = ESTIMATE_STATUS_META[estimate.status];
                        return (
                          <tr
                            key={estimate["@id"]}
                            className="border-b align-middle last:border-0"
                          >
                            <td className="px-4 py-3">
                              <Link
                                href={`/estimates/${estimate.id}`}
                                className="flex items-center gap-2 font-medium hover:opacity-80"
                              >
                                {estimate.number ?? "Draft"}
                                <span
                                  className={cn(
                                    "rounded px-1.5 py-0.5 text-xs font-medium",
                                    meta.badgeClass,
                                  )}
                                >
                                  {meta.label}
                                </span>
                                {estimate.convertedInvoice && (
                                  <span className="text-xs text-muted-foreground">
                                    → invoiced
                                  </span>
                                )}
                              </Link>
                            </td>
                            <td className="px-4 py-3 text-muted-foreground">
                              {estimateClientName(estimate.client) || "—"}
                            </td>
                            <td className="px-4 py-3 text-right font-medium tabular-nums">
                              {formatMoney(estimate.total, estimate.currency)}
                            </td>
                            <td className="px-4 py-3 text-right">
                              {canManage && (
                                <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                    <Button
                                      variant="ghost"
                                      size="icon"
                                      aria-label="Estimate actions"
                                    >
                                      <MoreHorizontal className="size-4" />
                                    </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                    {estimate.status !== "declined" && (
                                      <DropdownMenuItem
                                        onClick={() => act.mutate({ estimate, action: "send" })}
                                      >
                                        {estimate.status === "draft" ? "Send" : "Re-send"}
                                      </DropdownMenuItem>
                                    )}
                                    {estimate.status !== "declined" &&
                                      !estimate.convertedInvoice && (
                                        <DropdownMenuItem
                                          onClick={() =>
                                            act.mutate({ estimate, action: "convert" })
                                          }
                                        >
                                          Convert to invoice
                                        </DropdownMenuItem>
                                      )}
                                  </DropdownMenuContent>
                                </DropdownMenu>
                              )}
                            </td>
                          </tr>
                        );
                      })
                    )}
                  </tbody>
                </table>
              </div>
            </Card>
          )}
        </div>
      </div>
    </>
  );
};

export default EstimatesPage;
