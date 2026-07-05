import Link from "next/link";
import { useRouter } from "next/router";
import { ChevronRight, Home, Loader2 } from "lucide-react";
import { useQuery } from "@tanstack/react-query";
import { ENTRYPOINT } from "@/config/entrypoint";
import { cn } from "@/lib/utils";

/**
 * URL-derived breadcrumb trail for the navbar. Each segment is a link
 * to its level (the last one is the current page and isn't a link).
 *
 * Top-level segments come from a static label registry. UUID segments
 * are treated as entity ids — the previous segment names the resource,
 * and we lazy-fetch `/{resource}/{id}` to get the display name. The
 * fetch is cached via react-query so navigating around inside a space
 * or board doesn't re-hit the API on every page.
 *
 * Renders nothing on the home page and on auth screens; the Navbar
 * keeps the Madori wordmark visible there instead. On every other
 * authenticated route it stands in for the wordmark and anchors back
 * to `/` via the Home icon at the start.
 */

// Static top-level section labels. Anything missing falls back to a
// best-effort prettified version of the segment.
const SECTION_LABELS: Record<string, string> = {
  spaces: "Spaces",
  boards: "Boards",
  discussions: "Discussions",
  pages: "Pages",
  groups: "Groups",
  tasks: "Tasks",
  "my-tasks": "My tasks",
  search: "Search",
  settings: "Settings",
  account: "Account",
  profile: "Profile",
  security: "Security",
  "api-tokens": "API tokens",
  danger: "Danger zone",
  tags: "Tags",
  admin: "Admin",
  notifications: "Notifications",
  dev: "Dev",
};

// Mapping from a resource segment (e.g. "spaces") to the field on the
// fetched entity that should be displayed in the crumb. Only resources
// listed here trigger a fetch; everything else falls back to the raw
// id (shortened).
const ENTITY_DISPLAY_FIELD: Record<string, string> = {
  spaces: "name",
  boards: "title",
  discussions: "title",
  pages: "title",
  groups: "title",
};

// Routes where breadcrumbs shouldn't render at all.
const SKIP_PATHS = new Set([
  "/",
  "/signin",
  "/signup",
  "/forgot-password",
  "/reset-password",
  "/confirm-email-change",
  "/revert-email-change",
  "/privacy",
  "/terms",
  "/404",
  "/500",
  "/_error",
]);

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const isUuid = (s: string) => UUID_RE.test(s);

const prettify = (segment: string) =>
  segment
    .replace(/[-_]/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());

type Crumb =
  | { kind: "static"; label: string; href?: string }
  | { kind: "entity"; resource: string; id: string; href?: string };

function buildCrumbs(asPath: string): Crumb[] {
  const raw = asPath.split("?")[0].split("#")[0];
  const segments = raw.split("/").filter(Boolean);
  if (segments.length === 0) return [];

  const out: Crumb[] = [];
  let path = "";
  for (let i = 0; i < segments.length; i++) {
    const seg = decodeURIComponent(segments[i]);
    path += "/" + segments[i];

    if (i === 0) {
      out.push({
        kind: "static",
        label: SECTION_LABELS[seg] ?? prettify(seg),
        href: path,
      });
      continue;
    }

    const prev = decodeURIComponent(segments[i - 1]);
    if (isUuid(seg) && ENTITY_DISPLAY_FIELD[prev]) {
      out.push({ kind: "entity", resource: prev, id: seg, href: path });
      continue;
    }

    out.push({ kind: "static", label: prettify(seg), href: path });
  }

  // Final crumb represents the current page — drop its href so it
  // renders as plain text and gets `aria-current="page"`.
  const last = out[out.length - 1];
  if (last) {
    if (last.kind === "static") out[out.length - 1] = { ...last, href: undefined };
    else out[out.length - 1] = { ...last, href: undefined };
  }
  return out;
}

interface EntityResponse {
  [key: string]: unknown;
  name?: string;
  title?: string;
}

function useEntityLabel(resource: string, id: string) {
  return useQuery({
    queryKey: ["breadcrumb-entity", resource, id],
    queryFn: async (): Promise<EntityResponse | null> => {
      const res = await fetch(`${ENTRYPOINT}/${resource}/${id}`, {
        credentials: "include",
        headers: { Accept: "application/ld+json" },
      });
      if (!res.ok) return null;
      return (await res.json()) as EntityResponse;
    },
    staleTime: 60_000,
    // Don't retry — a 404 (left the space, lost access) shouldn't keep
    // hammering the API; we'll just show the id fallback.
    retry: false,
  });
}

function EntityCrumb({
  resource,
  id,
  href,
  isLast,
}: {
  resource: string;
  id: string;
  href?: string;
  isLast: boolean;
}) {
  const { data, isLoading } = useEntityLabel(resource, id);
  const field = ENTITY_DISPLAY_FIELD[resource];
  const fetched =
    data && typeof data[field] === "string" ? (data[field] as string) : null;
  const label = fetched ?? id.slice(0, 8);

  if (isLoading && !fetched) {
    return (
      <span className="inline-flex items-center text-muted-foreground">
        <Loader2 className="h-3 w-3 animate-spin" aria-hidden />
        <span className="sr-only">Loading</span>
      </span>
    );
  }

  return <StaticCrumb label={label} href={href} isLast={isLast} />;
}

function StaticCrumb({
  label,
  href,
  isLast,
}: {
  label: string;
  href?: string;
  isLast: boolean;
}) {
  if (isLast || !href) {
    return (
      <span
        className="text-foreground font-medium truncate"
        aria-current="page"
        title={label}
      >
        {label}
      </span>
    );
  }
  return (
    <Link
      href={href}
      className="text-muted-foreground hover:text-foreground no-underline truncate"
      title={label}
    >
      {label}
    </Link>
  );
}

const Breadcrumbs = ({ className }: { className?: string }) => {
  const router = useRouter();
  if (SKIP_PATHS.has(router.pathname)) return null;

  const crumbs = buildCrumbs(router.asPath);
  if (crumbs.length === 0) return null;

  return (
    <nav
      aria-label="Breadcrumb"
      className={cn(
        "min-w-0 flex items-center gap-1 text-sm overflow-hidden",
        className,
      )}
    >
      <Link
        href="/"
        aria-label="Home"
        className="text-muted-foreground hover:text-foreground shrink-0 inline-flex items-center"
      >
        <Home className="h-4 w-4" aria-hidden />
      </Link>
      <ol className="flex items-center gap-1 min-w-0">
        {crumbs.map((c, idx) => {
          const isLast = idx === crumbs.length - 1;
          return (
            <li
              key={`${idx}-${c.kind === "static" ? c.label : c.id}`}
              className="flex items-center gap-1 min-w-0"
            >
              <ChevronRight
                className="h-3.5 w-3.5 text-muted-foreground/60 shrink-0"
                aria-hidden
              />
              <span className="min-w-0 max-w-[16rem] inline-flex">
                {c.kind === "entity" ? (
                  <EntityCrumb
                    resource={c.resource}
                    id={c.id}
                    href={c.href}
                    isLast={isLast}
                  />
                ) : (
                  <StaticCrumb label={c.label} href={c.href} isLast={isLast} />
                )}
              </span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
};

export default Breadcrumbs;
