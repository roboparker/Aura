import Head from "next/head";
import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useMemo, useState } from "react";
import { ArrowDownAZ, Clock, Filter, ListFilter, Plus, Search, Users } from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace, type Space } from "@/contexts/ActiveSpaceContext";
import { signinHrefForCurrent } from "@/lib/authRedirect";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import PageHeader from "@/components/common/PageHeader";
import SpaceCard from "@/components/spaces/SpaceCard";

type FilterKey = "all" | "shared" | "personal" | "owned";
type SortKey = "recent" | "name" | "members";

const FILTERS: { key: FilterKey; label: string }[] = [
  { key: "all", label: "All" },
  { key: "shared", label: "Shared" },
  { key: "personal", label: "Personal" },
  { key: "owned", label: "Owned by me" },
];

const SORTS: { key: SortKey; label: string; icon: typeof Clock }[] = [
  { key: "recent", label: "Recent", icon: Clock },
  { key: "name", label: "Name", icon: ArrowDownAZ },
  { key: "members", label: "Members", icon: Users },
];

const effectiveMemberCount = (space: Space): number => {
  const direct = new Set(space.userMemberships.map((m) => m.user.id));
  // Group members aren't expanded on the list endpoint — counts that
  // include group-inherited users would require a separate roundtrip
  // per space. For now show the direct count; UI copy says "members"
  // which is honest about what's counted.
  return direct.size;
};

const SpacesIndex = () => {
  const { user, isAuthenticated, isLoading: authLoading } = useAuth();
  const { spaces, isLoading: spacesLoading } = useActiveSpace();
  const router = useRouter();

  const [filter, setFilter] = useState<FilterKey>("all");
  const [sort, setSort] = useState<SortKey>("recent");
  const [search, setSearch] = useState("");

  useEffect(() => {
    if (!authLoading && !isAuthenticated) {
      router.replace(signinHrefForCurrent(router.asPath));
    }
  }, [authLoading, isAuthenticated, router]);

  const isOwned = (space: Space) =>
    !!user && space.createdBy?.id === user.id;

  const counts = useMemo(
    () => ({
      all: spaces.length,
      shared: spaces.filter((s) => !s.isPersonal).length,
      personal: spaces.filter((s) => s.isPersonal).length,
      owned: spaces.filter(isOwned).length,
    }),
    // user is captured by isOwned closure; spaces drives recompute.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [spaces, user?.id],
  );

  const filteredSpaces = useMemo(() => {
    let out = spaces;
    if (filter === "shared") out = out.filter((s) => !s.isPersonal);
    if (filter === "personal") out = out.filter((s) => s.isPersonal);
    if (filter === "owned") out = out.filter(isOwned);

    const q = search.trim().toLowerCase();
    if (q) {
      out = out.filter(
        (s) =>
          s.name.toLowerCase().includes(q) ||
          (s.description ?? "").toLowerCase().includes(q),
      );
    }

    const sorted = [...out];
    if (sort === "recent") {
      sorted.sort(
        (a, b) =>
          new Date(b.updatedAt).getTime() - new Date(a.updatedAt).getTime(),
      );
    } else if (sort === "name") {
      sorted.sort((a, b) => a.name.localeCompare(b.name));
    } else if (sort === "members") {
      sorted.sort(
        (a, b) => effectiveMemberCount(b) - effectiveMemberCount(a),
      );
    }
    return sorted;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [spaces, filter, sort, search, user?.id]);

  const roleFor = (space: Space): "admin" | "member" | null => {
    if (!user) return null;
    const m = space.userMemberships.find((x) => x.user.id === user.id);
    return m?.role ?? null;
  };

  if (authLoading || !isAuthenticated || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <p className="text-muted-foreground">Loading…</p>
      </div>
    );
  }

  const sortLabel = SORTS.find((s) => s.key === sort)?.label ?? "Recent";

  return (
    <>
      <Head>
        <title>Spaces - Madori</title>
      </Head>

      <main className="px-6 py-8 max-w-7xl mx-auto">
        <PageHeader
          title="Spaces"
          subtitle="Workspaces and shared rooms you belong to. Each space has its own members, boards, and pages."
          actions={
            <>
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => {
                  // Mobile fallback that surfaces the filter chips
                  // when they collapse out of view. On desktop the
                  // chips below are always visible, so this is
                  // primarily a hint label.
                  document
                    .getElementById("spaces-filter-row")
                    ?.scrollIntoView({ behavior: "smooth", block: "start" });
                }}
              >
                <Filter className="h-3.5 w-3.5" />
                Filter
              </Button>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm" className="gap-1.5">
                    <ListFilter className="h-3.5 w-3.5" />
                    Sort: {sortLabel.toLowerCase()}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  {SORTS.map(({ key, label, icon: Icon }) => (
                    <DropdownMenuItem
                      key={key}
                      onSelect={() => setSort(key)}
                      className={cn("gap-2", sort === key && "font-semibold")}
                    >
                      <Icon className="h-3.5 w-3.5" />
                      {label}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
              <Button
                asChild
                size="sm"
                className="gap-1.5 bg-emerald-600 hover:bg-emerald-500 text-white"
              >
                <Link href="/spaces/new">
                  <Plus className="h-3.5 w-3.5" />
                  New space
                </Link>
              </Button>
            </>
          }
        />

        {/* Filter chips + search input row. */}
        <div
          id="spaces-filter-row"
          className="flex flex-wrap items-center justify-between gap-3 mb-4"
        >
          <div className="flex flex-wrap items-center gap-2">
            {FILTERS.map(({ key, label }) => {
              const active = filter === key;
              return (
                <Button
                  key={key}
                  type="button"
                  size="sm"
                  variant={active ? "default" : "outline"}
                  onClick={() => setFilter(key)}
                  className="gap-3"
                >
                  {label}
                  {/* Inline span instead of <Badge> so the count
                      doesn't pick up the badge's own hover styles —
                      the surrounding button already provides the
                      hover signal. */}
                  <span
                    aria-hidden
                    className={cn(
                      "inline-flex items-center justify-center rounded px-1.5 py-0.5 font-mono text-xs",
                      active ? "bg-background/20" : "bg-muted text-muted-foreground",
                    )}
                  >
                    {counts[key]}
                  </span>
                </Button>
              );
            })}
          </div>
          <div className="relative w-full sm:w-72">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
            <Input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search spaces…"
              className="pl-8"
            />
          </div>
        </div>

        {/* Section label + grid. */}
        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Your spaces
          <span className="text-muted-foreground/70">{filteredSpaces.length}</span>
        </div>

        {spacesLoading && spaces.length === 0 ? (
          <p className="text-muted-foreground">Loading spaces…</p>
        ) : filteredSpaces.length === 0 ? (
          <div className="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground">
            {search.trim() || filter !== "all"
              ? "No spaces match those filters."
              : "You don't belong to any spaces yet."}
          </div>
        ) : (
          <ul
            data-testid="space-list"
            className="grid gap-3 sm:grid-cols-1 lg:grid-cols-2"
          >
            {filteredSpaces.map((space) => (
              <li key={space["@id"]} data-testid="space-item">
                <SpaceCard
                  space={space}
                  role={roleFor(space)}
                  effectiveMemberCount={effectiveMemberCount(space)}
                />
              </li>
            ))}
          </ul>
        )}
      </main>
    </>
  );
};

export default SpacesIndex;
