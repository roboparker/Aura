import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { ENTRYPOINT } from "@/config/entrypoint";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { displayName } from "@/lib/userDisplay";
import UserAvatar, { type AvatarUser } from "@/components/user/UserAvatar";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

interface SpaceRef {
  "@id": string;
  id: string;
  name: string;
}

interface PageRow {
  "@id": string;
  id: string;
  title: string;
  body: string;
  position: number;
  parent: string | null;
  createdAt: string;
  updatedAt: string | null;
  space: SpaceRef;
  createdBy: AvatarUser & { "@id": string; id: string };
}

interface Collection<T> {
  member?: T[];
  "hydra:member"?: T[];
}

const RELATIVE = new Intl.RelativeTimeFormat(undefined, { numeric: "auto" });
const formatRelative = (iso: string): string => {
  const ts = new Date(iso).getTime();
  if (Number.isNaN(ts)) return "";
  const diffSec = Math.round((ts - Date.now()) / 1000);
  const abs = Math.abs(diffSec);
  if (abs < 60) return RELATIVE.format(diffSec, "second");
  if (abs < 3600) return RELATIVE.format(Math.round(diffSec / 60), "minute");
  if (abs < 86400) return RELATIVE.format(Math.round(diffSec / 3600), "hour");
  if (abs < 2592000) return RELATIVE.format(Math.round(diffSec / 86400), "day");
  if (abs < 31536000)
    return RELATIVE.format(Math.round(diffSec / 2592000), "month");
  return RELATIVE.format(Math.round(diffSec / 31536000), "year");
};

const PagesIndex = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { activeSpace } = useActiveSpace();
  const router = useRouter();

  const [pages, setPages] = useState<PageRow[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");

  // Composer
  const [showComposer, setShowComposer] = useState(false);
  const [newTitle, setNewTitle] = useState("");
  const [isCreating, setIsCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const load = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams({ itemsPerPage: "100" });
      if (activeSpace) params.set("space", activeSpace["@id"]);
      const trimmed = search.trim();
      if (trimmed) params.set("search", trimmed);
      const res = await fetch(`${ENTRYPOINT}/pages?${params.toString()}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) throw new Error("Failed to load pages.");
      const data: Collection<PageRow> = await res.json();
      setPages(data.member ?? data["hydra:member"] ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "Failed to load.");
    } finally {
      setIsLoading(false);
    }
  }, [activeSpace, search]);

  useEffect(() => {
    if (isAuthenticated) void load();
  }, [isAuthenticated, load]);

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!activeSpace || !newTitle.trim()) return;
    setIsCreating(true);
    setCreateError(null);
    try {
      const res = await fetch(`${ENTRYPOINT}/pages`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/ld+json" },
        body: JSON.stringify({
          space: activeSpace["@id"],
          title: newTitle.trim(),
          body: "",
        }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        throw new Error(
          data.detail || data["hydra:description"] || "Failed to create page.",
        );
      }
      const created: PageRow = await res.json();
      setNewTitle("");
      setShowComposer(false);
      await router.push(`/pages/${created.id}`);
    } catch (err) {
      setCreateError(err instanceof Error ? err.message : "Failed to create.");
    } finally {
      setIsCreating(false);
    }
  };

  if (authLoading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-muted">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  return (
    <>
      <Head>
        <title>Pages - Aura</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
          <header className="flex items-start justify-between gap-3">
            <div>
              <h1 className="text-2xl font-bold">Pages</h1>
              <p className="text-sm text-muted-foreground mt-1">
                {activeSpace
                  ? `Long-form documents in ${activeSpace.name}.`
                  : "Long-form documents in the spaces you belong to."}
              </p>
            </div>
            {activeSpace && (
              <Button
                size="sm"
                onClick={() => setShowComposer((v) => !v)}
                data-testid="new-page-button"
              >
                {showComposer ? "Cancel" : "New page"}
              </Button>
            )}
          </header>

          {showComposer && activeSpace && (
            <Card>
              <CardContent className="pt-6">
                <form onSubmit={handleCreate} className="space-y-3">
                  <div className="space-y-1.5">
                    <Label htmlFor="page-title">Title</Label>
                    <Input
                      id="page-title"
                      type="text"
                      value={newTitle}
                      onChange={(e) => setNewTitle(e.target.value)}
                      placeholder="Page title"
                      maxLength={200}
                      required
                      autoFocus
                    />
                  </div>
                  {createError && (
                    <Alert variant="destructive">
                      <AlertDescription>{createError}</AlertDescription>
                    </Alert>
                  )}
                  <Button
                    type="submit"
                    size="sm"
                    disabled={isCreating || !newTitle.trim()}
                  >
                    {isCreating ? "Creating…" : "Create page"}
                  </Button>
                </form>
              </CardContent>
            </Card>
          )}

          <div className="flex items-center gap-2">
            <Input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search pages…"
              className="max-w-sm"
              aria-label="Search pages"
            />
          </div>

          {error && (
            <Alert variant="destructive">
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          {isLoading ? (
            <p className="text-muted-foreground text-sm">Loading…</p>
          ) : pages.length === 0 ? (
            <Card>
              <CardContent className="pt-6">
                <p className="text-muted-foreground text-sm">
                  {search.trim()
                    ? "No pages match that search."
                    : activeSpace
                      ? "No pages in this space yet — start one with “New page”."
                      : "Switch to a space to start a page."}
                </p>
              </CardContent>
            </Card>
          ) : (
            <ul className="space-y-3" data-testid="pages-list">
              {pages.map((p) => (
                <li key={p["@id"]} data-testid="page-item">
                  <Card>
                    <CardContent className="pt-4 pb-4">
                      <div className="flex items-start gap-3">
                        <UserAvatar user={p.createdBy} size="sm" />
                        <div className="min-w-0 flex-1 space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <Link
                              href={`/pages/${p.id}`}
                              className="font-semibold text-foreground no-underline hover:underline"
                              data-testid="page-title"
                            >
                              {p.title}
                            </Link>
                            {p.parent && (
                              <Badge variant="outline">Sub-page</Badge>
                            )}
                          </div>
                          <p className="text-xs text-muted-foreground">
                            {p.space?.name && (
                              <>
                                <span className="font-medium">{p.space.name}</span>
                                {" · "}
                              </>
                            )}
                            {displayName(p.createdBy)} ·{" "}
                            {formatRelative(p.createdAt)}
                            {p.updatedAt && " · edited"}
                          </p>
                        </div>
                      </div>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </div>
      </main>
    </>
  );
};

export default PagesIndex;
