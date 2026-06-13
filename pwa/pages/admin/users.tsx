import type { NextPage } from "next";
import Head from "next/head";
import { useRouter } from "next/router";
import { useCallback, useEffect, useState } from "react";
import { VenetianMask } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { apiGetCollection } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import UserAvatar from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
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

interface AdminUser {
  id: string;
  email: string;
  givenName: string;
  familyName: string;
  nickname: string | null;
  personalizedColor: string;
  avatarUrls?: { thumb?: string; profile?: string } | null;
  roles?: string[];
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
          <title>Access Denied - Madori</title>
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
        <title>Users - Madori Admin</title>
      </Head>
      <div className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto space-y-6">
          <header>
            <h1 className="text-2xl font-bold">Users</h1>
            <p className="text-sm text-muted-foreground">
              Sign in as another user to test or debug an issue from their
              perspective. You can stop impersonating at any time.
            </p>
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
                  return (
                    <div
                      key={u.id}
                      className="flex items-center gap-3 rounded-md border p-3"
                      data-testid="users-row"
                    >
                      <UserAvatar user={u} size="sm" shape="square" />
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
                      <Button
                        variant="ghost"
                        size="icon"
                        disabled={isSelf}
                        onClick={() => setTarget(u)}
                        aria-label={`Impersonate ${displayName(u)}`}
                        title={isSelf ? "That's you" : "Impersonate"}
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

      <Dialog open={target !== null} onOpenChange={(o) => !o && setTarget(null)}>
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
    </>
  );
};

export default AdminUsers;
