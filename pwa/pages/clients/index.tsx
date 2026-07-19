import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link2, Pencil, PiggyBank, Plus, Users } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Client, formatMoney } from "@/lib/invoiceTypes";
import { CURRENCIES } from "@/lib/currencies";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const SELECT_CLASS = "h-9 w-full rounded-md border border-input bg-background px-3 text-sm";

const ClientsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [address, setAddress] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [defaultRate, setDefaultRate] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const spaceIri = activeSpace?.["@id"] ?? null;
  const clientsQuery = useQuery({
    queryKey: ["clients", spaceIri],
    enabled: isAuthenticated,
    queryFn: () =>
      apiGetCollection<Client>(
        spaceIri ? `/clients?space=${encodeURIComponent(spaceIri)}` : "/clients",
        { errorMessage: "Failed to load clients." },
      ),
  });
  const clients = clientsQuery.data ?? [];
  const refresh = () => queryClient.invalidateQueries({ queryKey: ["clients"] });

  const resetForm = () => {
    setEditingId(null);
    setName("");
    setEmail("");
    setAddress("");
    setCurrency("USD");
    setDefaultRate("");
    setShowComposer(false);
  };

  const startCreate = () => {
    resetForm();
    setShowComposer(true);
  };

  const startEdit = (client: Client) => {
    setEditingId(client.id);
    setName(client.name);
    setEmail(client.email ?? "");
    setAddress(client.address ?? "");
    setCurrency(client.currency ?? "USD");
    setDefaultRate(
      client.defaultRateAmount != null ? (client.defaultRateAmount / 100).toFixed(2) : "",
    );
    setActionError(null);
    setShowComposer(true);
  };

  // The rate field is only cleared (→ null) when explicitly emptied on an edit;
  // on create an empty field simply omits the key.
  const clientBody = () => {
    const rateMinor = defaultRate.trim() ? Math.round(parseFloat(defaultRate) * 100) : null;
    return {
      name: name.trim(),
      email: email.trim() || null,
      address: address.trim() || null,
      currency,
      defaultRateAmount: rateMinor,
    };
  };

  const saveMutation = useMutation({
    mutationFn: () =>
      editingId !== null
        ? apiSend<Client>("PATCH", `/clients/${editingId}`, {
            contentType: "application/merge-patch+json",
            errorMessage: "Failed to save client.",
            body: clientBody(),
          })
        : apiSend<Client>("POST", "/clients", {
            errorMessage: "Failed to create client.",
            body: { ...clientBody(), ...(spaceIri ? { space: spaceIri } : {}) },
          }),
    onSuccess: () => {
      setActionError(null);
      resetForm();
      void refresh();
    },
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to save client."),
  });

  const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!name.trim()) return;
    saveMutation.mutate();
  };

  // Client portal link (#674): mint/rotate, copy to clipboard, optional email.
  const portalMutation = useMutation({
    mutationFn: ({ client, send }: { client: Client; send: boolean }) =>
      apiSend<{ url: string; emailed: boolean }>(
        "POST",
        `/clients/${client.id}/portal-link`,
        {
          contentType: "application/json",
          errorMessage: "Failed to create the portal link.",
          body: { send },
        },
      ),
    onSuccess: async (res) => {
      setActionError(null);
      if (!res?.url) return;
      const suffix = res.emailed ? " and emailed to the client" : "";
      try {
        await navigator.clipboard.writeText(res.url);
        setNotice(`Portal link copied${suffix}. Older portal links no longer work.`);
      } catch {
        setNotice(`Portal link${suffix}: ${res.url}`);
      }
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to create the portal link."),
  });

  const mintPortal = (client: Client) => {
    const send =
      !!client.email &&
      window.confirm(`Also email the new portal link to ${client.email}?`);
    portalMutation.mutate({ client, send });
  };

  // Retainer deposits (#673): record pre-paid money; invoices draw it down.
  const depositMutation = useMutation({
    mutationFn: ({ client, amount }: { client: Client; amount: number }) =>
      apiSend<{ balance: number }>("POST", `/clients/${client.id}/retainer-deposits`, {
        contentType: "application/json",
        errorMessage: "Failed to record the deposit.",
        body: { amount },
      }),
    onSuccess: (res, { client }) => {
      setActionError(null);
      setNotice(
        `Deposit recorded. ${client.name}'s retainer balance: ${formatMoney(
          res?.balance ?? 0,
          client.currency ?? "USD",
        )}.`,
      );
    },
    onError: (e) =>
      setActionError(e instanceof Error ? e.message : "Failed to record the deposit."),
  });

  const addDeposit = (client: Client) => {
    const raw = window.prompt(`Retainer deposit for ${client.name} (amount)?`);
    if (raw === null) return;
    const amount = Math.round((parseFloat(raw) || 0) * 100);
    if (amount <= 0) {
      setActionError("Enter a positive amount.");
      return;
    }
    depositMutation.mutate({ client, amount });
  };

  const canCreate = can("invoices", "create");
  const canUpdate = can("invoices", "update");
  const error = actionError || (clientsQuery.isError ? "Failed to load clients." : null);

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
        <title>Clients — Madori</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="mx-auto max-w-4xl">
          <PageHeader
            title="Clients"
            icon={<Users className="size-6 text-blue-600 dark:text-blue-400" />}
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

          {clientsQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : (
            <Card>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-4 py-2 font-medium">Name</th>
                      <th className="px-4 py-2 font-medium">Email</th>
                      <th className="px-4 py-2 font-medium">Currency</th>
                      <th className="px-4 py-2 text-right font-medium">Rate / hr</th>
                      <th className="px-2 py-2" aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody>
                    {/* Inline add/edit client row, pinned to the top. */}
                    {(canCreate || (canUpdate && editingId !== null)) &&
                      (showComposer ? (
                        <tr className="border-b bg-muted/10">
                          <td colSpan={5} className="px-4 py-4">
                            <form onSubmit={handleSubmit} className="space-y-4">
                              <div className="space-y-1.5">
                                <Label htmlFor="cl-name">Name</Label>
                                <Input
                                  id="cl-name"
                                  value={name}
                                  onChange={(e) => setName(e.target.value)}
                                  required
                                  maxLength={200}
                                  autoFocus
                                />
                              </div>
                              <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                  <Label htmlFor="cl-email">
                                    Email{" "}
                                    <span className="font-normal text-muted-foreground">(optional)</span>
                                  </Label>
                                  <Input
                                    id="cl-email"
                                    type="email"
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                  />
                                </div>
                                <div className="grid grid-cols-2 gap-4">
                                  <div className="space-y-1.5">
                                    <Label htmlFor="cl-currency">Currency</Label>
                                    <select
                                      id="cl-currency"
                                      value={currency}
                                      onChange={(e) => setCurrency(e.target.value)}
                                      className={SELECT_CLASS}
                                    >
                                      {CURRENCIES.map((c) => (
                                        <option key={c.code} value={c.code}>
                                          {c.code}
                                        </option>
                                      ))}
                                    </select>
                                  </div>
                                  <div className="space-y-1.5">
                                    <Label htmlFor="cl-rate">Rate / hr</Label>
                                    <Input
                                      id="cl-rate"
                                      type="number"
                                      step="0.01"
                                      min="0"
                                      value={defaultRate}
                                      onChange={(e) => setDefaultRate(e.target.value)}
                                      placeholder="0.00"
                                    />
                                  </div>
                                </div>
                              </div>
                              <div className="space-y-1.5">
                                <Label htmlFor="cl-address">
                                  Address{" "}
                                  <span className="font-normal text-muted-foreground">(optional)</span>
                                </Label>
                                <Textarea
                                  id="cl-address"
                                  value={address}
                                  onChange={(e) => setAddress(e.target.value)}
                                  rows={2}
                                />
                              </div>
                              <div className="flex items-center justify-end gap-2">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={resetForm}
                                >
                                  Cancel
                                </Button>
                                <Button
                                  type="submit"
                                  size="sm"
                                  disabled={saveMutation.isPending || !name.trim()}
                                >
                                  {saveMutation.isPending
                                    ? "Saving…"
                                    : editingId !== null
                                      ? "Save changes"
                                      : "Create client"}
                                </Button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      ) : (
                        canCreate && (
                          <tr className="border-b">
                            <td colSpan={5} className="px-4 py-2">
                              <button
                                type="button"
                                onClick={startCreate}
                                className="flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                              >
                                <Plus className="h-4 w-4" /> Add client
                              </button>
                            </td>
                          </tr>
                        )
                      ))}

                    {clients.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">
                          No clients yet. Use “Add client” to start invoicing.
                        </td>
                      </tr>
                    ) : (
                      clients.map((client) => (
                        <tr key={client["@id"]} className="border-b align-middle last:border-0">
                          <td className="px-4 py-3 font-medium">{client.name}</td>
                          <td className="px-4 py-3 text-muted-foreground">{client.email || "—"}</td>
                          <td className="px-4 py-3 text-muted-foreground">{client.currency ?? "—"}</td>
                          <td className="px-4 py-3 text-right tabular-nums text-muted-foreground">
                            {client.defaultRateAmount != null && client.currency
                              ? formatMoney(client.defaultRateAmount, client.currency)
                              : "—"}
                          </td>
                          <td className="whitespace-nowrap px-2 py-3 text-right">
                            {canUpdate && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => startEdit(client)}
                                title="Edit this client's details"
                              >
                                <Pencil className="h-3.5 w-3.5" /> Edit
                              </Button>
                            )}
                            {canCreate && (
                              <>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => addDeposit(client)}
                                  disabled={depositMutation.isPending}
                                  title="Record a retainer deposit for this client"
                                >
                                  <PiggyBank className="h-3.5 w-3.5" /> Deposit
                                </Button>
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => mintPortal(client)}
                                  disabled={portalMutation.isPending}
                                  title="Create (or rotate) this client's billing portal link"
                                >
                                  <Link2 className="h-3.5 w-3.5" /> Portal
                                </Button>
                              </>
                            )}
                          </td>
                        </tr>
                      ))
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

export default ClientsPage;
