import Link from "next/link";
import { useRouter } from "next/router";
import { useEffect, useState, type ReactNode } from "react";
import { useQuery } from "@tanstack/react-query";
import {
  CalendarDays,
  ChevronDown,
  CreditCard,
  Plus,
  Settings,
  ShieldCheck,
  Tag,
  Users,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useActiveSpace } from "@/contexts/ActiveSpaceContext";
import { apiGetCollection } from "@/lib/apiClient";
import { CONTENT_SECTIONS } from "@/lib/contentSections";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import PagesNavTree from "./PagesNavTree";
import SpaceSwitcher from "./SpaceSwitcher";

// The account menu (avatar + personal links + sign out + stop
// impersonation) and the notification bell live in the top bar now —
// see UserMenu / Navbar. The sidebar carries the space switcher, the
// active space's content sections (projects / pages / discussions),
// and the admin section.

// The shared section is now a list of accessible spaces (rendered
// from ActiveSpaceContext at render time, since it's per-user state).
// "All Spaces" is the only static entry — it's the management
// surface; each space below is a link to its tabbed detail page
// (#nav-refresh) which is itself the hub for projects, discussions,
// pages, and tasks inside that space.

// Backend tooling surfaced inside the PWA chrome. The Mercure debugger
// is served by Caddy (FrankenPHP), not Next.js, so it's a regular <a>
// link rather than a <Link>. Admin-only.
//
// The debugger UI only exists when Caddy is started with the `demo`
// Mercure directive (MERCURE_EXTRA_DIRECTIVES=demo), which we set only
// in dev + e2e. Production leaves it unset, so /.well-known/mercure/ui/
// 404s there — hence we omit the link from production builds entirely
// rather than surface a dead admin link.
const ADMIN_EXTERNAL_LINKS =
  process.env.NODE_ENV === "production"
    ? []
    : [{ href: "/.well-known/mercure/ui/", label: "Mercure" }];

// The active space's content surfaces (Projects / Pages / Discussions)
// are rendered as their own sidebar sections from the shared
// CONTENT_SECTIONS map (icon + color shared with the aggregator page
// headers). Each heading links to its top-level aggregator (scoped to
// the active space, with the inline create composer); the items below
// are the active space's rows; an "add" link sits at the foot of each
// section. Headings + add link show even when the space has none, so
// the structure is always discoverable.

// Cap how many rows a section lists so a large space can't push the
// admin section (and the section below it) far off-screen. The heading
// links to the full aggregator for the rest.
const MAX_SECTION_ITEMS = 8;

interface ResourceRow {
  "@id": string;
  id: string;
  title: string;
}

/**
 * Fetch the active space's rows for one resource (`projects` / `pages`
 * / `discussions`), scoped by `?space=<iri>` — the same filter the
 * aggregators and SpaceContentTabs use.
 *
 * Keyed under the resource's react-query prefix (e.g. `["boards", …]`)
 * so the aggregators' `invalidateQueries({ queryKey: ["boards"] })`
 * on create/edit/delete refreshes this list too — a board added on
 * `/boards` shows up here without a manual reload.
 */
function useSpaceResources(
  spaceIri: string | null,
  resource: (typeof CONTENT_SECTIONS)[number]["resource"],
): ResourceRow[] {
  const query = useQuery({
    queryKey: [resource, spaceIri, "nav"],
    enabled: !!spaceIri,
    queryFn: () => {
      if (!spaceIri) return Promise.resolve<ResourceRow[]>([]);
      return apiGetCollection<ResourceRow>(
        `/${resource}?space=${encodeURIComponent(spaceIri)}&itemsPerPage=50`,
        { errorMessage: `Failed to load ${resource}.` },
      );
    },
  });

  // A failed fetch leaves the section as just its heading + add link
  // rather than breaking the nav.
  return query.data ?? [];
}

interface SidebarNavProps {
  /**
   * Wrap each interactive item — used by the mobile Sheet variant to
   * auto-close on selection. The persistent sidebar passes through.
   */
  itemWrapper?: (children: ReactNode) => ReactNode;
  /**
   * Render the active-space switcher at the top. The navbar shows the
   * switcher on its left at `md` and up, so only the mobile Sheet
   * variant (where there's no room for it in the top bar) sets this.
   */
  includeSpaceSwitcher?: boolean;
}

interface ContentSectionProps {
  section: (typeof CONTENT_SECTIONS)[number];
  spaceIri: string;
  wrap: (children: ReactNode) => ReactNode;
}

/**
 * One sidebar section: a heading (links to the aggregator) with a
 * collapse toggle, the active space's rows, and an "add" link. The
 * heading + toggle always render; the rows and "add" link collapse
 * behind the chevron, with the open/closed choice persisted per
 * resource in localStorage.
 */
const ContentSection = ({ section, spaceIri, wrap }: ContentSectionProps) => {
  const router = useRouter();
  const items = useSpaceResources(spaceIri, section.resource);
  const aggregatorHref = `/${section.resource}`;
  const shown = items.slice(0, MAX_SECTION_ITEMS);
  const currentPath = router.asPath.split("?")[0];
  const Icon = section.icon;

  // Persist the collapsed state per resource. Start expanded on the
  // server / first paint, then hydrate the stored choice after mount so
  // there's no SSR mismatch.
  const storageKey = `madori.navCollapsed.${section.resource}`;
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(storageKey) === "1");
  }, [storageKey]);

  const toggle = () =>
    setCollapsed((c) => {
      const next = !c;
      try {
        window.localStorage.setItem(storageKey, next ? "1" : "0");
      } catch {
        // Storage-disabled browsers: state still toggles for the session.
      }
      return next;
    });

  return (
    <div className="mt-3 first:mt-0">
      <div className="flex items-center px-3 pb-1">
        {wrap(
          <Link
            href={aggregatorHref}
            className="flex min-w-0 items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
          >
            <Icon className={cn("size-3.5 shrink-0", section.iconClass)} />
            <span className="truncate">{section.label}</span>
          </Link>,
        )}
        <button
          type="button"
          onClick={toggle}
          aria-expanded={!collapsed}
          aria-label={`${collapsed ? "Expand" : "Collapse"} ${section.label}`}
          className="ml-auto rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
        >
          <ChevronDown
            className={cn(
              "size-3.5 transition-transform",
              collapsed && "-rotate-90",
            )}
          />
        </button>
      </div>

      {!collapsed &&
        (() => {
          const addLink = (
            <span>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className="w-full justify-start font-normal text-muted-foreground"
                >
                  <Link href={aggregatorHref}>
                    <Plus className="size-4" />
                    New {section.singular}
                  </Link>
                </Button>,
              )}
            </span>
          );

          // Pages support drag-to-reorder + nesting in the menu; the rest are
          // a flat, capped list.
          if (section.resource === "pages") {
            return (
              <PagesNavTree
                spaceIri={spaceIri}
                currentPath={currentPath}
                wrap={wrap}
                addLink={addLink}
              />
            );
          }

          return (
            <>
              {shown.map((item) => {
                const href = `/${section.resource}/${item.id}`;
                return (
                  <span key={item["@id"]}>
                    {wrap(
                      <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className={cn(
                          "w-full min-w-0 justify-start font-normal",
                          currentPath === href &&
                            "bg-accent text-accent-foreground",
                        )}
                      >
                        <Link href={href}>
                          {/* pl-5 ≈ heading icon (size-3.5) + gap-1.5, so the
                              row text lines up under the heading label. */}
                          <span className="truncate pl-5">{item.title}</span>
                        </Link>
                      </Button>,
                    )}
                  </span>
                );
              })}
              {addLink}
            </>
          );
        })()}
    </div>
  );
};

interface AdminLink {
  href: string;
  label: string;
  /** Path prefix used to highlight the active row. */
  match: string;
  /** External (Caddy-served) link rendered as a plain <a>. */
  external?: boolean;
}

/**
 * Admin tooling as a collapsible nav section, mirroring {@see ContentSection}:
 * a colored icon + heading with a persisted collapse chevron, over the static
 * admin links. Lives inline in the scrollable nav (not pinned to the foot) so
 * it reads as just another section of the left menu.
 */
const AdminSection = ({ wrap }: { wrap: (children: ReactNode) => ReactNode }) => {
  const router = useRouter();

  const storageKey = "madori.navCollapsed.admin";
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(storageKey) === "1");
  }, []);

  const toggle = () =>
    setCollapsed((c) => {
      const next = !c;
      try {
        window.localStorage.setItem(storageKey, next ? "1" : "0");
      } catch {
        // Storage-disabled browsers: state still toggles for the session.
      }
      return next;
    });

  const links: AdminLink[] = [
    { href: "/admin/users", label: "Users", match: "/admin/users" },
    { href: "/admin/waitlist", label: "Waitlist", match: "/admin/waitlist" },
    { href: "/admin/segments", label: "Segments", match: "/admin/segments" },
    { href: "/admin/sso", label: "SSO", match: "/admin/sso" },
    {
      href: "/admin/global-custom-fields",
      label: "Global custom fields",
      match: "/admin/global-custom-fields",
    },
    { href: "/feedback", label: "Feedback", match: "/feedback" },
    ...ADMIN_EXTERNAL_LINKS.map((l) => ({ ...l, match: l.href, external: true })),
  ];

  return (
    <div className="mt-3 first:mt-0">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={!collapsed}
        aria-label={`${collapsed ? "Expand" : "Collapse"} Admin`}
        className="flex w-full items-center gap-1.5 px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
      >
        <ShieldCheck className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
        <span className="truncate">Admin</span>
        <ChevronDown
          className={cn(
            "ml-auto size-3.5 transition-transform",
            collapsed && "-rotate-90",
          )}
        />
      </button>

      {!collapsed &&
        links.map((link) => {
          const active = router.pathname.startsWith(link.match);
          const inner = (
            <Button
              asChild
              variant="ghost"
              size="sm"
              className={cn(
                "w-full min-w-0 justify-start font-normal",
                active && "bg-accent text-accent-foreground",
              )}
            >
              {link.external ? (
                <a href={link.href} target="_blank" rel="noopener noreferrer">
                  {/* pl-5 ≈ heading icon (size-3.5) + gap-1.5, lining the row
                      text up under the heading label. */}
                  <span className="truncate pl-5">{link.label}</span>
                </a>
              ) : (
                <Link href={link.href}>
                  <span className="truncate pl-5">{link.label}</span>
                </Link>
              )}
            </Button>
          );
          return <span key={link.href}>{wrap(inner)}</span>;
        })}
    </div>
  );
};

/**
 * Space membership + access management (Users, Roles, API keys) as a
 * collapsible nav group, mirroring {@see AdminSection}. Users + Roles are
 * space-admin only; API keys is gated on the `api_keys` read capability
 * (admins have it by default, a role can grant it to a member too). The
 * "General" space settings live in a standalone link above this group.
 */
const UserManagementSection = ({
  wrap,
}: {
  wrap: (children: ReactNode) => ReactNode;
}) => {
  const router = useRouter();
  const { activeSpace, isActiveSpaceAdmin, can } = useActiveSpace();

  const storageKey = "madori.navCollapsed.userManagement";
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(storageKey) === "1");
  }, []);

  const toggle = () =>
    setCollapsed((c) => {
      const next = !c;
      try {
        window.localStorage.setItem(storageKey, next ? "1" : "0");
      } catch {
        // Storage-disabled browsers: state still toggles for the session.
      }
      return next;
    });

  const links = [
    ...(activeSpace && isActiveSpaceAdmin
      ? [
          {
            href: `/spaces/${activeSpace.id}/users`,
            label: "Users",
            match: "/spaces/[id]/users",
          },
          {
            href: `/spaces/${activeSpace.id}/roles`,
            label: "Roles",
            match: "/spaces/[id]/roles",
          },
        ]
      : []),
    ...(activeSpace && can("api_keys", "read")
      ? [
          {
            href: `/spaces/${activeSpace.id}/api-keys`,
            label: "API keys",
            match: "/spaces/[id]/api-keys",
          },
        ]
      : []),
  ];

  if (links.length === 0) return null;

  return (
    <div className="mt-3 first:mt-0">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={!collapsed}
        aria-label={`${collapsed ? "Expand" : "Collapse"} User management`}
        className="flex w-full items-center gap-1.5 px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
      >
        <Users className="size-3.5 shrink-0 text-indigo-600 dark:text-indigo-400" />
        <span className="truncate">User management</span>
        <ChevronDown
          className={cn(
            "ml-auto size-3.5 transition-transform",
            collapsed && "-rotate-90",
          )}
        />
      </button>

      {!collapsed &&
        links.map((link) => {
          const active = router.pathname.startsWith(link.match);
          return (
            <span key={link.href}>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "w-full min-w-0 justify-start font-normal",
                    active && "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href={link.href}>
                    <span className="truncate pl-5">{link.label}</span>
                  </Link>
                </Button>,
              )}
            </span>
          );
        })}
    </div>
  );
};

/**
 * Content taxonomy tools (tags, custom fields) as a collapsible nav group,
 * mirroring {@see AdminSection}. Both are space-scoped and visible to every
 * member of the active space.
 */
const TaxonomySection = ({
  wrap,
}: {
  wrap: (children: ReactNode) => ReactNode;
}) => {
  const router = useRouter();

  const storageKey = "madori.navCollapsed.taxonomy";
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(storageKey) === "1");
  }, []);

  const toggle = () =>
    setCollapsed((c) => {
      const next = !c;
      try {
        window.localStorage.setItem(storageKey, next ? "1" : "0");
      } catch {
        // Storage-disabled browsers: state still toggles for the session.
      }
      return next;
    });

  const links = [
    { href: "/tags", label: "Tags", match: "/tags" },
    { href: "/custom-fields", label: "Custom fields", match: "/custom-fields" },
  ];

  return (
    <div className="mt-3 first:mt-0">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={!collapsed}
        aria-label={`${collapsed ? "Expand" : "Collapse"} Taxonomy`}
        className="flex w-full items-center gap-1.5 px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
      >
        <Tag className="size-3.5 shrink-0 text-teal-600 dark:text-teal-400" />
        <span className="truncate">Taxonomy</span>
        <ChevronDown
          className={cn(
            "ml-auto size-3.5 transition-transform",
            collapsed && "-rotate-90",
          )}
        />
      </button>

      {!collapsed &&
        links.map((link) => {
          const active = router.pathname.startsWith(link.match);
          return (
            <span key={link.href}>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "w-full min-w-0 justify-start font-normal",
                    active && "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href={link.href}>
                    <span className="truncate pl-5">{link.label}</span>
                  </Link>
                </Button>,
              )}
            </span>
          );
        })}
    </div>
  );
};

/**
 * Billing-related workspace tools (time tracking, clients, invoices) as a
 * collapsible nav group, mirroring {@see AdminSection}. Time is available to
 * every member; Clients + Invoices require the `invoices` read capability.
 */
const BillingSection = ({
  wrap,
  canInvoices,
}: {
  wrap: (children: ReactNode) => ReactNode;
  canInvoices: boolean;
}) => {
  const router = useRouter();

  const storageKey = "madori.navCollapsed.billing";
  const [collapsed, setCollapsed] = useState(false);
  useEffect(() => {
    if (typeof window === "undefined") return;
    setCollapsed(window.localStorage.getItem(storageKey) === "1");
  }, []);

  const toggle = () =>
    setCollapsed((c) => {
      const next = !c;
      try {
        window.localStorage.setItem(storageKey, next ? "1" : "0");
      } catch {
        // Storage-disabled browsers: state still toggles for the session.
      }
      return next;
    });

  const links = [
    { href: "/time", label: "Time", match: "/time", show: true },
    { href: "/engagements", label: "Engagements", match: "/engagements", show: canInvoices },
    { href: "/clients", label: "Clients", match: "/clients", show: canInvoices },
    { href: "/invoices", label: "Invoices", match: "/invoices", show: canInvoices },
  ].filter((l) => l.show);

  if (links.length === 0) return null;

  return (
    <div className="mt-3 first:mt-0">
      <button
        type="button"
        onClick={toggle}
        aria-expanded={!collapsed}
        aria-label={`${collapsed ? "Expand" : "Collapse"} Billing`}
        className="flex w-full items-center gap-1.5 px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
      >
        <CreditCard className="size-3.5 shrink-0 text-orange-600 dark:text-orange-400" />
        <span className="truncate">Billing</span>
        <ChevronDown
          className={cn(
            "ml-auto size-3.5 transition-transform",
            collapsed && "-rotate-90",
          )}
        />
      </button>

      {!collapsed &&
        links.map(({ href, label, match }) => {
          const active = router.pathname.startsWith(match);
          return (
            <span key={href}>
              {wrap(
                <Button
                  asChild
                  variant="ghost"
                  size="sm"
                  className={cn(
                    "w-full min-w-0 justify-start font-normal",
                    active && "bg-accent text-accent-foreground",
                  )}
                >
                  <Link href={href}>
                    {/* pl-5 ≈ heading icon (size-3.5) + gap-1.5, lining the row
                        text up under the heading label. */}
                    <span className="truncate pl-5">{label}</span>
                  </Link>
                </Button>,
              )}
            </span>
          );
        })}
    </div>
  );
};

/**
 * Shared navigation contents used by both the persistent left-side
 * `<Sidebar>` (`md:` and up) and the mobile-only Sheet variant in the
 * navbar. Single source of truth so the space switcher, content
 * sections, and admin section stay in sync across both surfaces.
 */
const SidebarNav = ({
  itemWrapper,
  includeSpaceSwitcher = false,
}: SidebarNavProps) => {
  const { user, isAuthenticated } = useAuth();
  const { activeSpace, isActiveSpaceAdmin, can } = useActiveSpace();
  const router = useRouter();
  const isAdmin = user?.roles?.includes("ROLE_ADMIN");
  const canInvoices = can("invoices", "read");
  const wrap = itemWrapper ?? ((c) => c);

  if (!isAuthenticated || !user) return null;

  const calendarActive = router.pathname === "/calendar";

  return (
    <div className="flex h-full flex-col">
      {includeSpaceSwitcher && (
        <div className="px-2 pt-4 pb-2">
          <SpaceSwitcher />
        </div>
      )}

      <nav className="flex flex-col gap-0.5 px-2 pt-2 pb-4 flex-1 overflow-y-auto">
        {activeSpace && (
          <span>
            {wrap(
              <Button
                asChild
                variant="ghost"
                size="sm"
                className={cn(
                  "w-full min-w-0 justify-start gap-1.5 font-normal",
                  calendarActive && "bg-accent text-accent-foreground",
                )}
              >
                <Link href="/calendar">
                  <CalendarDays className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                  <span className="truncate">Calendar</span>
                </Link>
              </Button>,
            )}
          </span>
        )}

        {activeSpace &&
          CONTENT_SECTIONS.map((section) => (
            <ContentSection
              key={section.resource}
              section={section}
              spaceIri={activeSpace["@id"]}
              wrap={wrap}
            />
          ))}

        {activeSpace && <TaxonomySection wrap={wrap} />}

        {activeSpace && <BillingSection wrap={wrap} canInvoices={canInvoices} />}

        <UserManagementSection wrap={wrap} />

        {activeSpace && isActiveSpaceAdmin && (
          <div className="mt-3">
            {wrap(
              <Button
                asChild
                variant="ghost"
                size="sm"
                className={cn(
                  "w-full min-w-0 justify-start gap-1.5 font-normal",
                  router.pathname === "/spaces/[id]/settings" &&
                    "bg-accent text-accent-foreground",
                )}
              >
                <Link href={`/spaces/${activeSpace.id}/settings`}>
                  <Settings className="size-3.5 shrink-0 text-muted-foreground" />
                  <span className="truncate">Settings</span>
                </Link>
              </Button>,
            )}
          </div>
        )}

        {isAdmin && <AdminSection wrap={wrap} />}
      </nav>
    </div>
  );
};

export default SidebarNav;
