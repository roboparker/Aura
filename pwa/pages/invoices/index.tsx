import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { MoreHorizontal, Receipt } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import {
  Client,
  Invoice,
  STATUS_META,
  clientName,
  formatMoney,
} from "@/lib/invoiceTypes";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { cn } from "@/lib/utils";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

type InvoiceAction = "issue" | "send" | "mark-paid" | "void";

const InvoicesPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [genClient, setGenClient] = useState("");
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;

  const invoicesQuery = useQuery({
    queryKey: ["invoices", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Invoice>(
        spaceIri ? `/invoices?space=${encodeURIComponent(spaceIri)}` : "/invoices",
        { errorMessage: "Failed to load invoices." },
      ),
  });
  const clientsQuery = useQuery({
    queryKey: ["clients", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Client>(
        spaceIri ? `/clients?space=${encodeURIComponent(spaceIri)}` : "/clients",
        { errorMessage: "Failed to load clients." },
      ),
  });
  const invoices = invoicesQuery.data ?? [];
  const clients = clientsQuery.data ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["invoices"] });

  const generate = useMutation({
    mutationFn: () =>
      apiSend<{ id: string; lineItemCount: number }>("POST", "/invoices/from-time-entries", {
        contentType: "application/json",
        errorMessage: "Failed to generate an invoice.",
        body: { space: spaceIri, client: genClient },
      }),
    onSuccess: (res) => {
      setError(null);
      setNotice(`Draft invoice created from ${res?.lineItemCount ?? 0} time entr${(res?.lineItemCount ?? 0) === 1 ? "y" : "ies"}.`);
      setGenClient("");
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Failed to generate an invoice."),
  });

  const act = useMutation({
    mutationFn: ({ invoice, action }: { invoice: Invoice; action: InvoiceAction }) =>
      apiSend<{ token?: string; publicUrl?: string }>("POST", `${invoice["@id"]}/${action}`, {
        contentType: "application/json",
        errorMessage: `Failed to ${action.replace("-", " ")} the invoice.`,
        body: {},
      }),
    onSuccess: (res, { action }) => {
      setError(null);
      if (action === "send" && res?.token) {
        setNotice(`Invoice sent. Shareable link: ${window.location.origin}/i/${res.token}`);
      } else {
        setNotice(null);
      }
      void refresh();
    },
    onError: (e) => setError(e instanceof Error ? e.message : "Action failed."),
  });

  const openPdf = async (invoice: Invoice) => {
    try {
      const res = await fetch(`${invoice["@id"]}/pdf`, {
        credentials: "include",
        headers: { Accept: "application/pdf" },
      });
      if (!res.ok) {
        setError("Could not open the PDF.");
        return;
      }
      const blobUrl = URL.createObjectURL(await res.blob());
      window.open(blobUrl, "_blank");
    } catch {
      setError("Could not open the PDF.");
    }
  };

  const canManage = can("invoices", "create");

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
        <title>Invoices — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Invoices"
            icon={<Receipt className="size-6 text-blue-600 dark:text-blue-400" />}
            count={invoicesQuery.isLoading ? undefined : invoices.length}
          />

          {canManage && (
            <Card className="mb-6">
              <CardContent className="flex flex-wrap items-end gap-3 py-4">
                <div className="space-y-1.5">
                  <Label htmlFor="gen-client">Generate from tracked time</Label>
                  <select
                    id="gen-client"
                    value={genClient}
                    onChange={(e) => setGenClient(e.target.value)}
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
                  onClick={() => generate.mutate()}
                  disabled={!genClient || generate.isPending}
                >
                  {generate.isPending ? "Generating…" : "Generate draft"}
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
              </CardContent>
            </Card>
          )}

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

          {invoicesQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : invoices.length === 0 ? (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                No invoices yet. Generate one from tracked time above.
              </CardContent>
            </Card>
          ) : (
            <ul className="divide-y rounded-md border">
              {invoices.map((invoice) => {
                const meta = STATUS_META[invoice.status];
                return (
                  <li key={invoice["@id"]} className="flex items-center justify-between gap-4 px-4 py-3">
                    <Link href={`/invoices/${invoice.id}`} className="min-w-0 flex-1 hover:opacity-80">
                      <p className="flex items-center gap-2 font-medium">
                        {invoice.number ?? "Draft"}
                        <span className={cn("rounded px-1.5 py-0.5 text-xs font-medium", meta.badgeClass)}>
                          {meta.label}
                        </span>
                      </p>
                      <p className="truncate text-xs text-muted-foreground">
                        {clientName(invoice.client) || "—"}
                      </p>
                    </Link>
                    <div className="flex shrink-0 items-center gap-3">
                      <span className="font-medium tabular-nums">
                        {formatMoney(invoice.total, invoice.currency)}
                      </span>
                      {canManage && (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label="Invoice actions">
                              <MoreHorizontal className="size-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => void openPdf(invoice)}>
                              View PDF
                            </DropdownMenuItem>
                            {invoice.status === "draft" && (
                              <DropdownMenuItem
                                onClick={() => act.mutate({ invoice, action: "issue" })}
                              >
                                Issue
                              </DropdownMenuItem>
                            )}
                            {invoice.status !== "void" && (
                              <DropdownMenuItem
                                onClick={() => act.mutate({ invoice, action: "send" })}
                              >
                                Send
                              </DropdownMenuItem>
                            )}
                            {invoice.status !== "paid" && invoice.status !== "void" && (
                              <DropdownMenuItem
                                onClick={() => act.mutate({ invoice, action: "mark-paid" })}
                              >
                                Mark paid
                              </DropdownMenuItem>
                            )}
                            {invoice.status !== "void" && (
                              <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  className="text-destructive"
                                  onClick={() => act.mutate({ invoice, action: "void" })}
                                >
                                  Void
                                </DropdownMenuItem>
                              </>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default InvoicesPage;
