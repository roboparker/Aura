import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Building2, Plus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGetCollection, apiSend, ApiError } from "@/lib/apiClient";
import {
  type Organization,
  type OrgRole,
  ORG_ROLE_LABELS,
} from "@/lib/organizationTypes";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import PageHeader from "@/components/common/PageHeader";

const OrganizationsIndex = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();

  const [orgs, setOrgs] = useState<Organization[]>([]);
  const [loading, setLoading] = useState(true);
  const [createOpen, setCreateOpen] = useState(false);
  const [name, setName] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const list = await apiGetCollection<Organization>("/organizations");
      setOrgs(list);
    } catch {
      setOrgs([]);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (isAuthenticated) void load();
  }, [isAuthenticated, load]);

  const roleFor = (org: Organization): OrgRole | null => {
    if (!user) return null;
    return org.memberList.find((m) => m.user.id === user.id)?.role ?? null;
  };

  const sorted = useMemo(
    () =>
      [...orgs].sort(
        (a, b) => new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime(),
      ),
    [orgs],
  );

  const handleCreate = async () => {
    const trimmed = name.trim();
    if (!trimmed) {
      setError("A name is required.");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const created = await apiSend<Organization>("POST", "/organizations", {
        body: { name: trimmed },
      });
      setCreateOpen(false);
      setName("");
      if (created?.id) {
        router.push(`/organizations/${created.id}`);
      } else {
        void load();
      }
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not create the organization.");
    } finally {
      setSubmitting(false);
    }
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Organizations - Madori</title>
      </Head>

      <div className="px-6 py-8 max-w-5xl mx-auto">
        <PageHeader
          title="Organizations"
          icon={<Building2 className="h-6 w-6 text-cyan-600" />}
          subtitle="Team accounts that own shared spaces and a subscription. Members occupy seats; guests are free."
          actions={
            <Button
              size="sm"
              className="gap-1.5"
              onClick={() => {
                setError(null);
                setName("");
                setCreateOpen(true);
              }}
            >
              <Plus className="h-3.5 w-3.5" />
              New organization
            </Button>
          }
        />

        {loading && orgs.length === 0 ? (
          <p className="text-muted-foreground">Loading organizations…</p>
        ) : sorted.length === 0 ? (
          <div className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
            You don&apos;t belong to any organizations yet. Create one to invite
            teammates and manage a shared subscription.
          </div>
        ) : (
          <ul className="grid gap-3 sm:grid-cols-1 lg:grid-cols-2" data-testid="org-list">
            {sorted.map((org) => {
              const role = roleFor(org);
              return (
                <li key={org["@id"]} data-testid="org-item">
                  <Link
                    href={`/organizations/${org.id}`}
                    className="flex items-start gap-3 rounded-lg border p-4 transition-colors hover:bg-accent"
                  >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-cyan-600/10 text-cyan-700">
                      <Building2 className="h-5 w-5" aria-hidden />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <p className="truncate font-semibold">{org.name}</p>
                        {role && (
                          <Badge variant="secondary" className="shrink-0">
                            {ORG_ROLE_LABELS[role]}
                          </Badge>
                        )}
                      </div>
                      <p className="truncate text-xs text-muted-foreground">
                        {org.slug}
                      </p>
                      <p className="mt-2 text-xs text-muted-foreground">
                        {org.seatCount} {org.seatCount === 1 ? "seat" : "seats"} ·{" "}
                        {org.memberList.length}{" "}
                        {org.memberList.length === 1 ? "member" : "members"}
                      </p>
                    </div>
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      </div>

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>New organization</DialogTitle>
            <DialogDescription>
              You&apos;ll be the owner. Add teammates and set up billing once it&apos;s created.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="org-name">Name</Label>
            <Input
              id="org-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Acme Inc"
              autoFocus
              onKeyDown={(e) => {
                if (e.key === "Enter" && !submitting) void handleCreate();
              }}
            />
            {error && <p className="text-sm text-destructive">{error}</p>}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setCreateOpen(false)} disabled={submitting}>
              Cancel
            </Button>
            <Button onClick={() => void handleCreate()} disabled={submitting}>
              {submitting ? "Creating…" : "Create organization"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
};

export default OrganizationsIndex;
