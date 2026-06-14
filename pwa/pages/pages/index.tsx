import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { FormEvent, useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { trackEvent } from "@/lib/analytics";
import { apiGetCollection, apiSend } from "@/lib/apiClient";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { contentSectionFor } from "@/lib/contentSections";
import { cn } from "@/lib/utils";
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
  const queryClient = useQueryClient();

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

  const spaceIri = activeSpace?.["@id"] ?? null;
  const pagesQuery = useQuery({
    queryKey: ["pages", spaceIri, search.trim()],
    enabled: isAuthenticated,
    queryFn: () => {
      const params = new URLSearchParams({ itemsPerPage: "100" });
      if (spaceIri) params.set("space", spaceIri);
      const trimmed = search.trim();
      if (trimmed) params.set("search", trimmed);
      return apiGetCollection<PageRow>(`/pages?${params.toString()}`, {
        errorMessage: "Failed to load pages.",
      });
    },
  });
  const pages = pagesQuery.data ?? [];
  const isLoading = pagesQuery.isLoading;
  const pagesMeta = contentSectionFor("pages");
  const PagesIcon = pagesMeta.icon;
  const error = pagesQuery.isError
    ? pagesQuery.error instanceof Error
      ? pagesQuery.error.message
      : "Failed to load."
    : null;

  const handleCreate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!activeSpace || !newTitle.trim()) return;
    setIsCreating(true);
    setCreateError(null);
    try {
      const created = await apiSend<PageRow>("POST", "/pages", {
        errorMessage: "Failed to create page.",
        body: { space: activeSpace["@id"], title: newTitle.trim(), body: "" },
      });
      trackEvent("page-create");
      setNewTitle("");
      setShowComposer(false);
      // Refresh the sidebar nav list (shares the ["pages"] key prefix).
      void queryClient.invalidateQueries({ queryKey: ["pages"] });
      if (created) await router.push(`/pages/${created.id}`);
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
        <title>Pages - Madori</title>
      </Head>
      <main className="min-h-screen bg-muted">
        <div className="max-w-4xl mx-auto px-4 py-8 space-y-6">
          <header className="flex items-start justify-between gap-3">
            <div>
              <div className="flex items-center gap-2">
                <PagesIcon className={cn("h-6 w-6 shrink-0", pagesMeta.iconClass)} />
                <h1 className="text-2xl font-bold">Pages</h1>
                {!isLoading && (
                  <span
                    className="rounded-full bg-muted-foreground/15 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                    data-testid="pages-count"
                  >
                    {pages.length}
                  </span>
                )}
              </div>
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
