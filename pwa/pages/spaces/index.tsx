import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const SpacesIndex = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces, refresh, isLoading: spacesLoading } = useActiveSpace();
  const router = useRouter();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!name.trim()) return;

    setIsSubmitting(true);
    setError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/spaces`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          name: name.trim(),
          description: description.trim() || null,
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.description ||
            data.detail ||
            data["hydra:description"] ||
            "Failed to create space.",
        );
      }
      setName("");
      setDescription("");
      // Reload the user's space list so the new entry shows up in the
      // list AND becomes available in the navbar switcher.
      await refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to create space.");
    } finally {
      setIsSubmitting(false);
    }
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  // Personal space first, then shared by created date (the API already
  // sorts that way; preserve it on the client).
  const ordered = [...spaces].sort((a, b) =>
    a.isPersonal === b.isPersonal ? 0 : a.isPersonal ? -1 : 1,
  );

  return (
    <>
      <Head>
        <title>Spaces - Aura</title>
      </Head>
      <main className="min-h-screen bg-muted px-4 py-12">
        <div className="max-w-2xl mx-auto">
          <h1 className="text-2xl font-bold mb-2">Spaces</h1>
          <p className="text-sm text-muted-foreground mb-6">
            A space is the home for a set of projects, discussions, and
            members. Your private space comes with your account; create
            shared spaces to collaborate.
          </p>

          <Card className="mb-6">
            <CardContent className="pt-6">
              <form onSubmit={handleCreate} className="space-y-4" data-testid="create-space-form">
                <div className="space-y-1.5">
                  <Label htmlFor="name">Name</Label>
                  <Input
                    id="name"
                    type="text"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    required
                    maxLength={255}
                    placeholder="Backend team"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="description">
                    Description{" "}
                    <span className="text-muted-foreground font-normal">(optional)</span>
                  </Label>
                  <Input
                    id="description"
                    type="text"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    maxLength={500}
                  />
                </div>
                <Button type="submit" disabled={isSubmitting || !name.trim()}>
                  {isSubmitting ? "Creating…" : "Create space"}
                </Button>
              </form>
            </CardContent>
          </Card>

          {error && (
            <Alert variant="destructive" className="mb-4">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {spacesLoading && spaces.length === 0 ? (
            <p className="text-muted-foreground">Loading spaces…</p>
          ) : (
            <ul className="space-y-2" data-testid="space-list">
              {ordered.map((space) => {
                const isAdmin = space.userMemberships.some(
                  (m) => m.user.id === user.id && m.role === "admin",
                );
                return (
                  <li key={space["@id"]} data-testid="space-item">
                    <Card>
                      <CardContent className="pt-4 pb-4">
                        <div className="flex items-start justify-between gap-3">
                          <div>
                            <h2 className="font-semibold">
                              <Link
                                href={`/spaces/${space.id}`}
                                className="text-primary hover:underline no-underline"
                              >
                                {space.name}
                              </Link>
                            </h2>
                            {space.description && (
                              <p className="mt-1 text-sm text-muted-foreground">
                                {space.description}
                              </p>
                            )}
                          </div>
                          <div className="flex gap-1 shrink-0">
                            {space.isPersonal && (
                              <Badge variant="secondary">Private</Badge>
                            )}
                            {isAdmin && !space.isPersonal && (
                              <Badge variant="secondary">Admin</Badge>
                            )}
                          </div>
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">
                          {space.userMemberships.length}{" "}
                          {space.userMemberships.length === 1 ? "member" : "members"}
                          {space.groupMemberships.length > 0 && (
                            <>
                              {" · "}
                              {space.groupMemberships.length}{" "}
                              {space.groupMemberships.length === 1 ? "group" : "groups"}
                            </>
                          )}
                        </p>
                      </CardContent>
                    </Card>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </main>
    </>
  );
};

export default SpacesIndex;
