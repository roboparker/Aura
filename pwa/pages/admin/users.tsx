import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { UserPlus, VenetianMask } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { pageTitle } from "@/lib/pageTitle";

interface AdminUser {
  id: string;
  email: string;
  givenName: string;
  familyName: string;
  nickname: string | null;
  personalizedColor: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
  roles?: string[];
  /** Whether this user has opted in to admin impersonation. */
  canBeImpersonated?: boolean;
}

/** Response of POST /admin/users — `password` is returned once, never again. */
interface CreatedUser {
  id: string;
  email: string;
  password: string;
  waitlisted: boolean;
  space: { id: string; name: string } | null;
}

const isAdminUser = (u: AdminUser): boolean =>
  Array.isArray(u.roles) && u.roles.includes("ROLE_ADMIN");

const AdminUsers: NextPage = () => {
  const { user, isAuthenticated, isLoading, impersonateUser } = useAuth();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [query, setQuery] = useState("");
  const [error, setError] = useState<string | null>(null);
  // The user a confirm dialog is currently asking about, or null.
  const [target, setTarget] = useState<AdminUser | null>(null);
  const [busy, setBusy] = useState(false);

  // Create-user dialog (#admin-user-create).
  const [createOpen, setCreateOpen] = useState(false);
  const [newEmail, setNewEmail] = useState("");
  const [newGiven, setNewGiven] = useState("");
  const [newFamily, setNewFamily] = useState("");
  const [skipWaitlist, setSkipWaitlist] = useState(true);
  const [newSpace, setNewSpace] = useState("");
  const [createError, setCreateError] = useState<string | null>(null);
  // The one-shot result — the password is only ever returned once.
  const [created, setCreated] = useState<CreatedUser | null>(null);

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [isLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    try {
      const data = await apiGetCollection<AdminUser>("/users", {
        errorMessage: "Couldn't load users.",
      });
      setUsers(data);
    } catch {
      setError("Couldn't load users.");
    }
  }, []);

  useEffect(() => {
    if (isAdmin) load();
  }, [isAdmin, load]);

  const confirmImpersonate = useCallback(async () => {
    if (!target) return;
    setBusy(true);
    setError(null);
    try {
      await impersonateUser(target.email);
      // Land on the app home as the impersonated user; the auth + space
      // contexts re-resolve off the new user id automatically.
      router.push("/");
    } catch (e) {
      setError(
        e instanceof Error ? e.message : `Couldn't impersonate ${target.email}.`,
      );
      setTarget(null);
    } finally {
      setBusy(false);
    }
  }, [target, impersonateUser, router]);

  const resetCreateForm = useCallback(() => {
    setNewEmail("");
    setNewGiven("");
    setNewFamily("");
    setNewSpace("");
    setSkipWaitlist(true);
    setCreateError(null);
    setCreated(null);
  }, []);

  const submitCreate = useCallback(async () => {
    setBusy(true);
    setCreateError(null);
    try {
      const res = await apiSend<CreatedUser>("POST", "/admin/users", {
        contentType: "application/json",
        errorMessage: "Couldn't create the user.",
        body: {
          email: newEmail.trim(),
          givenName: newGiven.trim(),
          familyName: newFamily.trim(),
          skipWaitlist,
          ...(newSpace.trim() ? { spaceName: newSpace.trim() } : {}),
        },
      });
      setCreated(res);
      void load();
    } catch (e) {
      setCreateError(
        e instanceof Error ? e.message : "Couldn't create the user.",
      );
    } finally {
      setBusy(false);
    }
  }, [newEmail, newGiven, newFamily, skipWaitlist, newSpace, load]);

  if (isLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  if (!isAdmin) {
    return (
      <>
        <Head>
          <title>{pageTitle("Access Denied")}</title>
        </Head>
        <div className="min-h-screen flex items-center justify-center bg-muted px-4">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-foreground mb-2">Access Denied</h1>
            <p className="text-muted-foreground">
              You need administrator privileges to view this page.
            </p>
          </div>
        </div>
      </>
    );
  }

  const needle = query.trim().toLowerCase();
  const visible = needle
    ? users.filter(
        (u) =>
          u.email.toLowerCase().includes(needle) ||
          displayName(u).toLowerCase().includes(needle),
      )
    : users;

  return (
    <>
      <Head>
        <title>{pageTitle("Users", "Admin")}</title>
      </Head>
      <div className="min-h-screen bg-background px-4 py-12">
        <div className="max-w-5xl mx-auto space-y-6">
          <header className="flex items-start justify-between gap-4">
            <div>
              <h1 className="text-2xl font-bold">Users</h1>
              <p className="text-sm text-muted-foreground">
                Sign in as another user to test or debug an issue from their
                perspective. You can stop impersonating at any time.
              </p>
            </div>
            <Button
              onClick={() => {
                resetCreateForm();
                setCreateOpen(true);
              }}
              data-testid="create-user-open"
            >
              <UserPlus className="h-4 w-4" /> Create user
            </Button>
          </header>

          {error && (
            <Alert variant="destructive" data-testid="users-error">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <Card data-testid="users-card">
            <CardHeader>
              <CardTitle>All users</CardTitle>
              <CardDescription>
                Click the mask icon to impersonate a user.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <Input
                placeholder="Search by name or email…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                aria-label="Search users"
              />
              <div className="space-y-2">
                {visible.map((u) => {
                  const isSelf = u.id === user?.id;
                  const optedIn = u.canBeImpersonated === true;
                  const canImpersonate = !isSelf && optedIn;
                  return (
                    <div
                      key={u.id}
                      className="flex items-center gap-3 rounded-md border p-3"
                      data-testid="users-row"
                    >
                      <UserAvatar user={u} size="sm" />
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium truncate">
                          {displayName(u)}
                          {isAdminUser(u) && (
                            <Badge variant="secondary" className="ml-2 align-middle">
                              Admin
                            </Badge>
                          )}
                        </p>
                        <p className="text-xs text-muted-foreground truncate">
                          {u.email}
                        </p>
                      </div>
                      {!isSelf && !optedIn && (
                        <span
                          className="hidden text-xs text-muted-foreground sm:inline"
                          data-testid="users-impersonation-disabled"
                        >
                          This user has not enabled impersonation
                        </span>
                      )}
                      <Button
                        variant="ghost"
                        size="icon"
                        disabled={!canImpersonate}
                        onClick={() => setTarget(u)}
                        aria-label={`Impersonate ${displayName(u)}`}
                        title={
                          isSelf
                            ? "That's you"
                            : optedIn
                              ? "Impersonate"
                              : "This user has not enabled impersonation"
                        }
                        data-testid="users-impersonate"
                      >
                        <VenetianMask className="h-4 w-4" />
                      </Button>
                    </div>
                  );
                })}
                {visible.length === 0 && (
                  <p className="py-6 text-center text-sm text-muted-foreground">
                    No users match “{query}”.
                  </p>
                )}
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      <Dialog open={target !== null} onOpenChange={(o: boolean) => !o && setTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Impersonate this user?</DialogTitle>
            <DialogDescription>
              You&apos;ll browse Madori as{" "}
              <span className="font-medium">
                {target ? displayName(target) : ""}
              </span>{" "}
              ({target?.email}). A banner stays on screen and a “Stop
              impersonation” option sits in the account menu until you switch
              back.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="ghost"
              onClick={() => setTarget(null)}
              disabled={busy}
            >
              Cancel
            </Button>
            <Button
              onClick={() => void confirmImpersonate()}
              disabled={busy}
              data-testid="users-impersonate-confirm"
            >
              {busy ? "Switching…" : "Impersonate"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Create user (#admin-user-create) — the password is shown once. */}
      <Dialog
        open={createOpen}
        onOpenChange={(o: boolean) => {
          setCreateOpen(o);
          if (!o) resetCreateForm();
        }}
      >
        <DialogContent>
          {created ? (
            <>
              <DialogHeader>
                <DialogTitle>User created</DialogTitle>
                <DialogDescription>
                  Copy the password now — it is shown once and never stored in
                  plaintext.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-3" data-testid="create-user-result">
                <div className="space-y-1">
                  <Label>Email</Label>
                  <Input readOnly value={created.email} />
                </div>
                <div className="space-y-1">
                  <Label>Password</Label>
                  <Input
                    readOnly
                    value={created.password}
                    data-testid="create-user-password"
                    onFocus={(e) => e.currentTarget.select()}
                  />
                </div>
                {created.space && (
                  <p className="text-sm text-muted-foreground">
                    Shared space <strong>{created.space.name}</strong> created,
                    with this user as admin.
                  </p>
                )}
                {created.waitlisted && (
                  <Alert>
                    <AlertDescription>
                      This account is still on the waitlist, so it can&apos;t use
                      the app until it&apos;s granted access.
                    </AlertDescription>
                  </Alert>
                )}
              </div>
              <DialogFooter>
                <Button
                  onClick={() => {
                    void navigator.clipboard
                      ?.writeText(created.password)
                      .catch(() => undefined);
                  }}
                  variant="outline"
                >
                  Copy password
                </Button>
                <Button onClick={() => setCreateOpen(false)}>Done</Button>
              </DialogFooter>
            </>
          ) : (
            <>
              <DialogHeader>
                <DialogTitle>Create a user</DialogTitle>
                <DialogDescription>
                  Creates a sign-in-ready account — email confirmation is skipped
                  and a password is generated for you.
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-3">
                {createError && (
                  <Alert variant="destructive" data-testid="create-user-error">
                    <AlertDescription>{createError}</AlertDescription>
                  </Alert>
                )}
                <div className="space-y-1">
                  <Label htmlFor="new-email">Email</Label>
                  <Input
                    id="new-email"
                    type="email"
                    value={newEmail}
                    onChange={(e) => setNewEmail(e.target.value)}
                    data-testid="create-user-email"
                  />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <Label htmlFor="new-given">First name</Label>
                    <Input
                      id="new-given"
                      value={newGiven}
                      onChange={(e) => setNewGiven(e.target.value)}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="new-family">Last name</Label>
                    <Input
                      id="new-family"
                      value={newFamily}
                      onChange={(e) => setNewFamily(e.target.value)}
                    />
                  </div>
                </div>
                <div className="space-y-1">
                  <Label htmlFor="new-space">
                    Shared space{" "}
                    <span className="font-normal text-muted-foreground">
                      (optional)
                    </span>
                  </Label>
                  <Input
                    id="new-space"
                    placeholder="e.g. Acme Studio"
                    value={newSpace}
                    onChange={(e) => setNewSpace(e.target.value)}
                  />
                </div>
                <label className="flex items-center gap-2 text-sm">
                  <Checkbox
                    checked={skipWaitlist}
                    onCheckedChange={(c) => setSkipWaitlist(c === true)}
                  />
                  Skip the waitlist (account can use the app immediately)
                </label>
              </div>
              <DialogFooter>
                <Button variant="ghost" onClick={() => setCreateOpen(false)}>
                  Cancel
                </Button>
                <Button
                  onClick={() => void submitCreate()}
                  disabled={
                    busy ||
                    !newEmail.trim() ||
                    !newGiven.trim() ||
                    !newFamily.trim()
                  }
                  data-testid="create-user-submit"
                >
                  {busy ? "Creating…" : "Create user"}
                </Button>
              </DialogFooter>
            </>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
};

export default AdminUsers;
