import Head from "next/head";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Users } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Client, formatMoney } from "@/lib/invoiceTypes";
import { CURRENCIES } from "@/lib/currencies";
import PageHeader from "@/components/common/PageHeader";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const ClientsPage = () => {
  const { isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace, can } = useActiveSpace();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [showComposer, setShowComposer] = useState(false);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [address, setAddress] = useState("");
  const [currency, setCurrency] = useState("USD");
  const [defaultRate, setDefaultRate] = useState("");
  const [actionError, setActionError] = useState<string | null>(null);

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

  const createMutation = useMutation({
    mutationFn: () => {
      const rateMinor = defaultRate.trim() ? Math.round(parseFloat(defaultRate) * 100) : null;
      return apiSend<Client>("POST", "/clients", {
        errorMessage: "Failed to create client.",
        body: {
          name: name.trim(),
          email: email.trim() || null,
          address: address.trim() || null,
          currency,
          ...(rateMinor !== null ? { defaultRateAmount: rateMinor } : {}),
          ...(spaceIri ? { space: spaceIri } : {}),
        },
      });
    },
    onSuccess: () => {
      setName("");
      setEmail("");
      setAddress("");
      setDefaultRate("");
      setShowComposer(false);
      setActionError(null);
      void refresh();
    },
    onError: (e) => setActionError(e instanceof Error ? e.message : "Failed to create client."),
  });

  const handleCreate = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!name.trim()) return;
    createMutation.mutate();
  };

  const canCreate = can("invoices", "create");
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
            count={clientsQuery.isLoading ? undefined : clients.length}
            actions={
              canCreate ? (
                <Button variant="outline" size="sm" onClick={() => setShowComposer((v) => !v)}>
                  {showComposer ? "Cancel" : "New client"}
                </Button>
              ) : undefined
            }
          />

          {showComposer && (
            <Card className="mb-6">
              <CardContent className="py-6">
                <form onSubmit={handleCreate} className="space-y-4">
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
                        Email <span className="font-normal text-muted-foreground">(optional)</span>
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
                          className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
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
                      Address <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea
                      id="cl-address"
                      value={address}
                      onChange={(e) => setAddress(e.target.value)}
                      rows={2}
                    />
                  </div>
                  <Button type="submit" disabled={createMutation.isPending || !name.trim()}>
                    {createMutation.isPending ? "Saving…" : "Create client"}
                  </Button>
                </form>
              </CardContent>
            </Card>
          )}

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {clientsQuery.isLoading ? (
            <p className="text-muted-foreground">Loading…</p>
          ) : clients.length === 0 ? (
            <Card>
              <CardContent className="py-10 text-center text-muted-foreground">
                No clients yet. Add one to start invoicing.
              </CardContent>
            </Card>
          ) : (
            <ul className="divide-y rounded-md border">
              {clients.map((client) => (
                <li key={client["@id"]} className="flex items-center justify-between gap-4 px-4 py-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{client.name}</p>
                    {client.email && (
                      <p className="truncate text-xs text-muted-foreground">{client.email}</p>
                    )}
                  </div>
                  <div className="shrink-0 text-right text-sm text-muted-foreground">
                    {client.currency ?? "—"}
                    {client.defaultRateAmount != null && client.currency && (
                      <span> · {formatMoney(client.defaultRateAmount, client.currency)}/hr</span>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </>
  );
};

export default ClientsPage;
