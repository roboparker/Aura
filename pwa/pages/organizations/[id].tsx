import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useCallback, useEffect, useMemo, useState } from "react";
import { ArrowLeft, Building2, Check, MoreHorizontal, Settings, Trash2, UserPlus } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { apiGet, apiSend, ApiError } from "@/lib/apiClient";
import {
  type Organization,
  type OrgMember,
  type OrgRole,
  isOrgAdminRole,
  ORG_ROLE_DESCRIPTIONS,
  ORG_ROLE_LABELS,
  ORG_ROLES,
  toOrgAvatarUser,
} from "@/lib/organizationTypes";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/lib/utils";

// Roles an admin can assign from the member row. Owner transfer is a heavier
// action (kept out of the quick menu until a dedicated flow exists).
const ASSIGNABLE_ROLES: OrgRole[] = ["admin", "billing", "member", "guest"];

const OrganizationDetail = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const router = useRouter();
  const orgId = typeof router.query.id === "string" ? router.query.id : null;

  const [org, setOrg] = useState<Organization | null>(null);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [inviteEmail, setInviteEmail] = useState("");
  const [inviteRole, setInviteRole] = useState<OrgRole>("member");
  const [inviting, setInviting] = useState(false);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    if (!orgId) return;
    setLoading(true);
    setNotFound(false);
    try {
      const data = await apiGet<Organization>(`/organizations/${orgId}`);
      setOrg(data);
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) setNotFound(true);
      setOrg(null);
    } finally {
      setLoading(false);
    }
  }, [orgId]);

  useEffect(() => {
    if (isAuthenticated && orgId) void load();
  }, [isAuthenticated, orgId, load]);

  const myRole: OrgRole | null = useMemo(() => {
    if (!org || !user) return null;
    return org.memberList.find((m) => m.user.id === user.id)?.role ?? null;
  }, [org, user]);

  const isAdmin = isOrgAdminRole(myRole);

  const ownerCount = useMemo(
    () => (org?.memberList.filter((m) => m.role === "owner").length ?? 0),
    [org],
  );

  const addMember = async () => {
    const email = inviteEmail.trim().toLowerCase();
    if (!email) return;
    setInviting(true);
    setError(null);
    try {
      await apiSend("POST", `/organizations/${orgId}/members`, {
        body: { email, role: inviteRole },
      });
      setInviteEmail("");
      setInviteRole("member");
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not add the member.");
    } finally {
      setInviting(false);
    }
  };

  const changeRole = async (member: OrgMember, role: OrgRole) => {
    if (member.role === role) return;
    setError(null);
    try {
      await apiSend("PATCH", `/organizations/${orgId}/members/${member.user.id}`, {
        body: { role },
        contentType: "application/merge-patch+json",
      });
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not change the role.");
    }
  };

  const removeMember = async (member: OrgMember) => {
    setError(null);
    try {
      await apiSend("DELETE", `/organizations/${orgId}/members/${member.user.id}`);
      await load();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not remove the member.");
    }
  };

  if (authLoading || !isAuthenticated) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (notFound) {
    return (
      <div className="px-6 py-16 max-w-2xl mx-auto text-center">
        <h1 className="text-lg font-semibold">Organization not found</h1>
        <p className="mt-2 text-sm text-muted-foreground">
          It may have been deleted, or you&apos;re no longer a member.
        </p>
        <Button asChild variant="outline" className="mt-4">
          <Link href="/organizations">Back to organizations</Link>
        </Button>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>{org ? `${org.name} - Madori` : "Organization - Madori"}</title>
      </Head>

      <div className="px-6 py-8 max-w-4xl mx-auto">
        <Link
          href="/organizations"
          className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          Organizations
        </Link>

        {loading && !org ? (
          <p className="text-muted-foreground">Loading…</p>
        ) : !org ? (
          <p className="text-muted-foreground">Couldn&apos;t load this organization.</p>
        ) : (
          <>
            <div className="mb-6 flex items-start gap-3">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-md bg-cyan-600/10 text-cyan-700">
                <Building2 className="h-6 w-6" aria-hidden />
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <h1 className="truncate text-xl font-semibold">{org.name}</h1>
                  {myRole && (
                    <Badge variant="secondary">{ORG_ROLE_LABELS[myRole]}</Badge>
                  )}
                </div>
                <p className="text-sm text-muted-foreground">{org.slug}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  {org.seatCount} {org.seatCount === 1 ? "seat" : "seats"} ·{" "}
                  {org.memberList.length}{" "}
                  {org.memberList.length === 1 ? "member" : "members"}
                </p>
              </div>
              {isAdmin && (
                <Button asChild variant="outline" size="sm" className="gap-1.5 shrink-0">
                  <Link href={`/organizations/${org.id}/settings`}>
                    <Settings className="h-3.5 w-3.5" />
                    Settings
                  </Link>
                </Button>
              )}
            </div>

            {error && (
              <Alert variant="destructive" className="mb-4">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            )}

            {isAdmin && (
              <section className="mb-6 rounded-lg border p-4">
                <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                  <UserPlus className="h-4 w-4" aria-hidden />
                  Add a member
                </h2>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                  <div className="flex-1 space-y-1.5">
                    <Label htmlFor="invite-email">Email</Label>
                    <Input
                      id="invite-email"
                      type="email"
                      value={inviteEmail}
                      onChange={(e) => setInviteEmail(e.target.value)}
                      placeholder="teammate@example.com"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label>Role</Label>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="outline" className="w-full sm:w-36 justify-between">
                          {ORG_ROLE_LABELS[inviteRole]}
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="start">
                        {ASSIGNABLE_ROLES.map((role) => (
                          <DropdownMenuItem key={role} onSelect={() => setInviteRole(role)}>
                            <div>
                              <p className="font-medium">{ORG_ROLE_LABELS[role]}</p>
                              <p className="text-xs text-muted-foreground">
                                {ORG_ROLE_DESCRIPTIONS[role]}
                              </p>
                            </div>
                          </DropdownMenuItem>
                        ))}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </div>
                  <Button onClick={() => void addMember()} disabled={inviting || !inviteEmail.trim()}>
                    {inviting ? "Adding…" : "Add"}
                  </Button>
                </div>
                <p className="mt-2 text-xs text-muted-foreground">
                  The person must already have a Madori account. Guests are free and
                  don&apos;t count toward seats.
                </p>
              </section>
            )}

            <section className="rounded-lg border">
              <div className="border-b px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Members
              </div>
              <ul className="divide-y" data-testid="org-member-list">
                {org.memberList.map((member) => {
                  const isSelf = member.user.id === user?.id;
                  const isLastOwner = member.role === "owner" && ownerCount <= 1;
                  return (
                    <li
                      key={member.id}
                      className="flex items-center gap-3 px-4 py-3"
                      data-testid="org-member"
                    >
                      <UserAvatar user={toOrgAvatarUser(member.user)} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                          {displayName(member.user)}
                          {isSelf && (
                            <span className="ml-1.5 text-xs text-muted-foreground">(you)</span>
                          )}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                          {member.user.email}
                        </p>
                      </div>
                      {isAdmin ? (
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="sm" className="gap-1.5">
                              {ORG_ROLE_LABELS[member.role]}
                              <MoreHorizontal className="h-3.5 w-3.5" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            {ORG_ROLES.map((role) => (
                              <DropdownMenuItem
                                key={role}
                                onSelect={() => void changeRole(member, role)}
                                disabled={isLastOwner && role !== "owner"}
                                className={cn("gap-2", member.role === role && "font-semibold")}
                              >
                                <Check
                                  className={cn(
                                    "h-3.5 w-3.5",
                                    member.role === role ? "opacity-100" : "opacity-0",
                                  )}
                                />
                                {ORG_ROLE_LABELS[role]}
                              </DropdownMenuItem>
                            ))}
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onSelect={() => void removeMember(member)}
                              disabled={isLastOwner}
                              className="gap-2 text-destructive focus:text-destructive"
                            >
                              <Trash2 className="h-3.5 w-3.5" />
                              Remove
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      ) : (
                        <Badge variant="outline">{ORG_ROLE_LABELS[member.role]}</Badge>
                      )}
                    </li>
                  );
                })}
              </ul>
            </section>
          </>
        )}
      </div>
    </>
  );
};

export default OrganizationDetail;
