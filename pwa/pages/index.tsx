import { type ReactNode } from "react";
import Head from "next/head";
import Link from "next/link";
import {
  ArrowRight,
  ArrowUpDown,
  Bell,
  Boxes,
  Check,
  ChevronDown,
  ChevronRight,
  Code2,
  FileText,
  Filter,
  ListTodo,
  MessageSquare,
  MessagesSquare,
  Pin,
  Plus,
  Search,
  ShieldCheck,
  SlidersHorizontal,
  Users,
  Zap,
  type LucideIcon,
} from "lucide-react";
import { useAuth } from "@/contexts/AuthContext";
import { useSignupStatus } from "@/lib/useSignupStatus";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/* ────────────────────────────────────────────────────────────────────────
 * Logged-out marketing homepage.
 *
 * Translated from the "Landing Page" design in the Madori design file, mapped
 * onto the app's real tokens (primary = brand teal, card/muted/border) and
 * Tailwind/shadcn primitives. The top nav + footer come from the app shell
 * (Navbar + Footer in Layout), so this page renders hero → features →
 * Spaces showcase → strip → steps → CTA only. The product "screenshots" are
 * static, presentational mockups built from the same visual language.
 * ──────────────────────────────────────────────────────────────────────── */

// Soft teal glow that matches the brand drop-shadow used on the wordmark.
const GLOW = "shadow-[0_0_30px_-6px_rgb(62_221_180/0.45)]";

/** rgba tint from a hex colour, for the inline-styled mock chips/tags. */
const tint = (hex: string, a: number) => {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`;
};

// ── Mock people (presentational only — colored initials) ──────────────────
type Person = { initials: string; color: string };
const M: Record<string, Person> = {
  maya: { initials: "MP", color: "#e0794b" },
  theo: { initials: "TR", color: "#6c8cff" },
  sana: { initials: "SK", color: "#3ec8a8" },
  dev: { initials: "DV", color: "#c879e6" },
  liam: { initials: "LO", color: "#e6b450" },
  nadia: { initials: "NA", color: "#5fb0e0" },
};

const MiniStack = ({
  people,
  size = 18,
  ring = "ring-card",
}: {
  people: Person[];
  size?: number;
  ring?: string;
}) => (
  <div className="flex -space-x-1.5">
    {people.map((p, i) => (
      <span
        key={i}
        className={cn(
          "inline-flex items-center justify-center rounded-full font-semibold text-white ring-2",
          ring,
        )}
        style={{
          width: size,
          height: size,
          backgroundColor: p.color,
          fontSize: size * 0.42,
        }}
      >
        {p.initials}
      </span>
    ))}
  </div>
);

// ── Hero product mockup: a Space with a task list + docs sidebar ──────────
const HERO_PROJECTS = [
  { name: "Spring Campaign Launch", active: true },
  { name: "Website refresh", active: false },
  { name: "Content calendar", active: false },
  { name: "Ops & vendors", active: false },
];
const HERO_PAGES = [
  { title: "Acme Marketing — Handbook", open: true },
  { title: "Q2 retro", open: false },
  { title: "Onboarding · new marketer", open: false },
];

const TAG_META: Record<string, { label: string; color: string }> = {
  legal: { label: "legal", color: "#e6b450" },
  copy: { label: "copy", color: "#6c8cff" },
  "launch-blocker": { label: "launch-blocker", color: "#43d4b0" },
  "paid-media": { label: "paid-media", color: "#c879e6" },
  research: { label: "research", color: "#3ec8a8" },
};

type Task = {
  id: string;
  done: boolean;
  title: string;
  tag: keyof typeof TAG_META;
  due: string;
  who: Person[];
};
const HERO_TASKS: Task[] = [
  {
    id: "SCL-04",
    done: false,
    title: "Legal sign-off on testimonial language",
    tag: "legal",
    due: "Mar 25",
    who: [M.dev],
  },
  {
    id: "SCL-02",
    done: false,
    title: "Landing page — long-form copy v3",
    tag: "copy",
    due: "Mar 28",
    who: [M.theo],
  },
  {
    id: "SCL-01",
    done: false,
    title: "Final hero photography selects",
    tag: "launch-blocker",
    due: "Apr 2",
    who: [M.sana, M.maya],
  },
  {
    id: "SCL-06",
    done: false,
    title: "Influencer brief + first outreach",
    tag: "paid-media",
    due: "Mar 30",
    who: [M.sana],
  },
  {
    id: "SCL-07",
    done: true,
    title: "Pre-launch survey to customers",
    tag: "research",
    due: "Mar 20",
    who: [M.nadia],
  },
];

const TagChip = ({ tag }: { tag: keyof typeof TAG_META }) => {
  const { label, color } = TAG_META[tag];
  return (
    <span
      className="inline-flex items-center rounded px-1.5 py-0.5 text-[10.5px] font-medium"
      style={{
        color,
        backgroundColor: tint(color, 0.14),
        border: `1px solid ${tint(color, 0.3)}`,
      }}
    >
      {label}
    </span>
  );
};

const MockCheckbox = ({ checked }: { checked: boolean }) => (
  <span
    className={cn(
      "flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border",
      checked ? "border-primary bg-primary" : "border-input",
    )}
  >
    {checked && <Check className="h-2.5 w-2.5 text-primary-foreground" strokeWidth={3} />}
  </span>
);

// Generic browser-style window frame shared by both mockups.
const Frame = ({ children }: { children: ReactNode }) => (
  <div
    className={cn(
      "overflow-hidden rounded-xl border border-input bg-card",
      "shadow-[0_40px_90px_-40px_rgb(0_0_0/0.7)]",
      GLOW,
    )}
  >
    {children}
  </div>
);

const SpaceChip = ({
  letter,
  name,
  hue,
}: {
  letter: string;
  name: string;
  hue: number;
}) => (
  <div className="flex items-center gap-1.5">
    <span
      className="flex h-[18px] w-[18px] items-center justify-center rounded-[5px] text-[10px] font-bold text-primary-foreground"
      style={{ background: `oklch(0.7 0.16 ${hue})` }}
    >
      {letter}
    </span>
    <span className="text-[12.5px] font-medium text-foreground">{name}</span>
  </div>
);

const HeroAppMock = () => (
  <Frame>
    {/* window bar */}
    <div className="flex h-[42px] items-center gap-2.5 border-b bg-background px-3">
      <SpaceChip letter="A" name="Acme Marketing" hue={22} />
      <ChevronDown className="h-3 w-3 text-muted-foreground" />
      <span className="flex-1" />
      <span className="inline-flex items-center gap-1.5 rounded-md border bg-muted px-2 py-1 text-muted-foreground">
        <Search className="h-3 w-3" />
        <span className="font-mono text-[10px]">⌘K</span>
      </span>
      <MiniStack people={[M.maya]} size={20} ring="ring-background" />
    </div>

    <div className="flex h-[332px] bg-card">
      {/* projects + pages sidebar */}
      <div className="w-[168px] shrink-0 overflow-hidden border-r bg-background px-2.5 py-3">
        <div className="px-1 pb-1.5 text-[9.5px] font-semibold uppercase tracking-wide text-muted-foreground">
          Projects
        </div>
        <div className="mb-3.5 flex flex-col gap-px">
          {HERO_PROJECTS.map((p) => (
            <div
              key={p.name}
              className={cn(
                "truncate px-2 py-1 text-[12px]",
                p.active
                  ? "rounded-r border-l-2 border-primary font-medium text-foreground shadow-[inset_8px_0_10px_-8px_var(--primary)]"
                  : "border-l-2 border-transparent text-muted-foreground",
              )}
            >
              {p.name}
            </div>
          ))}
        </div>
        <div className="px-1 pb-1.5 text-[9.5px] font-semibold uppercase tracking-wide text-muted-foreground">
          Pages
        </div>
        <div className="flex flex-col gap-px">
          {HERO_PAGES.map((pg) => (
            <div
              key={pg.title}
              className="flex items-center gap-1 px-1.5 py-0.5 text-[11.5px] text-muted-foreground"
            >
              {pg.open ? (
                <ChevronRight className="h-2.5 w-2.5 rotate-90 text-muted-foreground" />
              ) : (
                <span className="w-2.5" />
              )}
              <span className="truncate">{pg.title}</span>
            </div>
          ))}
        </div>
      </div>

      {/* main — task list */}
      <div className="flex min-w-0 flex-1 flex-col">
        <div className="border-b px-3.5 py-3">
          <div className="flex items-center gap-2.5">
            <h3 className="text-sm font-semibold tracking-tight text-foreground">
              Spring Campaign Launch
            </h3>
            <span className="flex-1" />
            <MiniStack people={[M.theo, M.sana, M.maya, M.liam]} size={18} />
          </div>
          <div className="mt-2.5 flex gap-1.5">
            <span
              className={cn(
                "inline-flex items-center gap-1 rounded px-2 py-1 text-[11.5px] font-medium text-primary-foreground",
                "bg-primary",
                GLOW,
              )}
            >
              <Plus className="h-2.5 w-2.5" /> Add task
            </span>
            <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-1 text-[11.5px] font-medium text-muted-foreground">
              <Filter className="h-2.5 w-2.5" /> Filter
            </span>
            <span className="inline-flex items-center gap-1 rounded bg-muted px-2 py-1 text-[11.5px] font-medium text-muted-foreground">
              <ArrowUpDown className="h-2.5 w-2.5" /> Sort
            </span>
          </div>
        </div>
        <div className="px-3.5 pb-1.5 pt-2.5 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
          This week · 4
        </div>
        <div>
          {HERO_TASKS.map((t, i) => (
            <div
              key={t.id}
              className={cn(
                "relative flex items-center gap-2.5 px-3 py-[7px]",
                i > 0 && "border-t",
              )}
            >
              {t.tag === "launch-blocker" && (
                <span className="absolute bottom-1.5 left-0 top-1.5 w-0.5 rounded-full bg-primary" />
              )}
              <MockCheckbox checked={t.done} />
              <span className="w-[42px] shrink-0 font-mono text-[10.5px] text-muted-foreground">
                {t.id}
              </span>
              <span
                className={cn(
                  "min-w-0 flex-1 truncate text-[12.5px]",
                  t.done ? "text-foreground/50 line-through" : "text-foreground",
                )}
              >
                {t.title}
              </span>
              <span className="shrink-0">
                <TagChip tag={t.tag} />
              </span>
              <span className="w-12 shrink-0 text-right font-mono text-[10.5px] text-muted-foreground">
                {t.due}
              </span>
              <span className="shrink-0">
                <MiniStack people={t.who} size={18} />
              </span>
            </div>
          ))}
        </div>
      </div>
    </div>
  </Frame>
);

// ── Spaces showcase mockup ────────────────────────────────────────────────
const SpaceTile = ({
  icon: Icon,
  label,
  count,
  children,
}: {
  icon: LucideIcon;
  label: string;
  count: number;
  children: ReactNode;
}) => (
  <div className="min-w-0 rounded-lg border bg-background p-3">
    <div className="mb-2.5 flex items-center gap-1.5">
      <Icon className="h-3.5 w-3.5 text-primary" />
      <span className="text-[11.5px] font-semibold text-foreground">{label}</span>
      <span className="flex-1" />
      <span className="font-mono text-[10px] text-muted-foreground">{count}</span>
    </div>
    <div className="flex flex-col gap-1.5">{children}</div>
  </div>
);

const TileLine = ({
  children,
  dim,
}: {
  children: ReactNode;
  dim?: boolean;
}) => (
  <div
    className={cn(
      "flex items-center gap-1.5 truncate text-[11.5px]",
      dim ? "text-muted-foreground" : "text-foreground/80",
    )}
  >
    {children}
  </div>
);

const SpaceShowcaseMock = () => (
  <Frame>
    <div className="flex h-[42px] items-center gap-2 border-b bg-background px-3">
      <SpaceChip letter="B" name="Brand Council" hue={168} />
      <span className="ml-0.5 rounded-[3px] border bg-muted px-1.5 py-px font-mono text-[9.5px] uppercase tracking-wide text-muted-foreground">
        shared
      </span>
      <span className="flex-1" />
      <MiniStack
        people={[M.maya, M.sana, M.liam, M.nadia]}
        size={18}
        ring="ring-background"
      />
    </div>
    <div className="bg-card p-4">
      <div className="mb-3.5 flex items-baseline gap-2.5">
        <h3 className="text-[15px] font-semibold tracking-tight text-foreground">
          Brand Council
        </h3>
        <span className="text-[11.5px] text-muted-foreground">
          6 members · everything in one place
        </span>
      </div>
      <div className="grid grid-cols-2 gap-2.5">
        <SpaceTile icon={Boxes} label="Projects" count={2}>
          <TileLine>
            <span className="h-1.5 w-1.5 shrink-0 rounded-[2px] bg-primary" />
            Identity system v2
          </TileLine>
          <TileLine dim>
            <span className="h-1.5 w-1.5 shrink-0 rounded-[2px] bg-muted-foreground" />
            Motion language
          </TileLine>
        </SpaceTile>
        <SpaceTile icon={ListTodo} label="Tasks" count={12}>
          <TileLine>
            <MockCheckbox checked={false} />
            <span className="truncate">Finalize type scale</span>
          </TileLine>
          <TileLine>
            <MockCheckbox checked />
            <span className="truncate text-muted-foreground line-through">
              Color tokens
            </span>
          </TileLine>
        </SpaceTile>
        <SpaceTile icon={FileText} label="Pages" count={5}>
          <TileLine>
            <FileText className="h-3 w-3 text-muted-foreground" />
            Brand voice
          </TileLine>
          <TileLine dim>
            <FileText className="h-3 w-3 text-muted-foreground" />
            House style
          </TileLine>
        </SpaceTile>
        <SpaceTile icon={MessagesSquare} label="Discussions" count={3}>
          <TileLine>
            <Pin className="h-2.5 w-2.5 text-primary" />
            Spring palette approval
          </TileLine>
          <TileLine dim>
            <MessageSquare className="h-2.5 w-2.5 text-muted-foreground" />
            Earnest vs. playful?
          </TileLine>
        </SpaceTile>
      </div>
    </div>
  </Frame>
);

// ── Section content ───────────────────────────────────────────────────────
const FEATURES: { icon: LucideIcon; title: string; desc: string }[] = [
  {
    icon: Boxes,
    title: "Spaces",
    desc: "Shared containers for everything your team works on — a private space, plus shared ones you invite people into.",
  },
  {
    icon: ListTodo,
    title: "Tasks",
    desc: "Capture in seconds, drag to reorder, due dates, recurrence, custom fields, and reminders.",
  },
  {
    icon: FileText,
    title: "Pages",
    desc: "Long-form docs with a nested page tree and rich markdown — your handbook, retros, and specs.",
  },
  {
    icon: MessagesSquare,
    title: "Discussions",
    desc: "Lightweight forum threads per space, with categories, pins, and live replies.",
  },
  {
    icon: Search,
    title: "Search & ⌘K",
    desc: "Full-text search across tasks, docs, and discussions — reachable from a command palette anywhere.",
  },
  {
    icon: Bell,
    title: "Notifications",
    desc: "A live inbox for mentions, assignments, and comments — in-app, email, and push.",
  },
];

const STRIP: { icon: LucideIcon; label: string }[] = [
  { icon: Users, label: "Groups" },
  { icon: SlidersHorizontal, label: "Custom fields" },
  { icon: Zap, label: "Real-time updates" },
  { icon: ShieldCheck, label: "2FA & security" },
  { icon: Code2, label: "MCP / API access" },
];

const SPACE_BULLETS = [
  {
    b: "One Space, four surfaces.",
    t: "Projects, tasks, pages, and discussions all live together — not scattered across five disconnected tools.",
  },
  {
    b: "Invite once, see everything.",
    t: "Add a person or a group to a Space and they get the whole picture — no per-document sharing dance.",
  },
  {
    b: "Private by default.",
    t: "Everyone gets a private Space for their own work, alongside the shared ones they belong to.",
  },
];

const STEPS = [
  {
    n: "01",
    title: "Create your account",
    desc: "Sign up free in under a minute. You get a private Space the moment you land.",
  },
  {
    n: "02",
    title: "Invite your team to a Space",
    desc: "Add people or a group once — they see every project, page, and discussion inside.",
  },
  {
    n: "03",
    title: "Start shipping",
    desc: "Capture tasks, write the doc, open a thread. Everything stays in one calm place.",
  },
];

const Eyebrow = ({ children }: { children: ReactNode }) => (
  <div className="font-mono text-[11px] font-medium uppercase tracking-wider text-primary">
    {children}
  </div>
);

const WRAP = "mx-auto w-full max-w-[1180px] px-6";

const Home = () => {
  const { isAuthenticated } = useAuth();
  const { waitlistEnabled } = useSignupStatus();
  const signupCta = waitlistEnabled ? "Join the waitlist" : "Get started — free";

  return (
    <>
      <Head>
        <title>Madori — one calm place for your team&apos;s work</title>
        <meta
          name="description"
          content="Madori organizes tasks, projects, long-form docs, and discussions into shared Spaces. A calm, collaborative workspace for small teams — no bloat, no busywork."
        />
      </Head>

      <main className="relative overflow-hidden">
        {/* atmospheric glows */}
        <div
          aria-hidden
          className="pointer-events-none absolute -right-32 -top-48 h-[520px] w-[680px] rounded-full"
          style={{
            background:
              "radial-gradient(closest-side, rgb(62 221 180 / 0.12), transparent 72%)",
          }}
        />
        <div
          aria-hidden
          className="pointer-events-none absolute -left-52 top-[420px] h-[560px] w-[560px] rounded-full"
          style={{
            background:
              "radial-gradient(closest-side, rgb(62 221 180 / 0.08), transparent 72%)",
          }}
        />

        {/* Hero */}
        <section className={cn(WRAP, "relative")}>
          <div className="grid items-center gap-14 py-16 md:py-20 lg:grid-cols-2">
            <div>
              <span
                className={cn(
                  "inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/10 px-3 py-1.5",
                  "shadow-[0_0_24px_-6px_rgb(62_221_180/0.4)]",
                )}
              >
                <span className="h-1.5 w-1.5 rounded-full bg-primary shadow-[0_0_8px_0_rgb(62_221_180)]" />
                <span className="font-mono text-[10.5px] font-medium uppercase tracking-wider text-primary">
                  Now in beta
                </span>
              </span>
              <h1 className="mt-5 text-balance text-4xl font-semibold leading-[1.05] tracking-tight text-foreground md:text-5xl lg:text-[3.5rem]">
                Everything your team is working on, in one{" "}
                <span className="text-primary">calm</span> place.
              </h1>
              <p className="mt-5 max-w-md text-base leading-relaxed text-muted-foreground">
                Tasks, projects, long-form docs, and discussions — organized
                into shared Spaces. No bloat, no busywork. Just the work, and the
                people doing it.
              </p>
              <div className="mt-7 flex flex-wrap items-center gap-3">
                {isAuthenticated ? (
                  <Button asChild size="lg" className={GLOW}>
                    <Link href="/tasks">
                      Go to your tasks <ArrowRight className="h-4 w-4" />
                    </Link>
                  </Button>
                ) : (
                  <>
                    <Button asChild size="lg" className={GLOW}>
                      <Link href="/signup">{signupCta}</Link>
                    </Button>
                    <Button asChild size="lg" variant="secondary">
                      <Link href="/signin">Sign in</Link>
                    </Button>
                  </>
                )}
              </div>
              <div className="mt-7 flex items-center gap-2.5 text-xs text-muted-foreground">
                <MiniStack
                  people={[M.theo, M.sana, M.liam, M.maya, M.dev]}
                  size={22}
                  ring="ring-background"
                />
                <span>Small teams shipping calmly — no credit card required.</span>
              </div>
            </div>
            <div className="order-first lg:order-none">
              <HeroAppMock />
            </div>
          </div>
        </section>

        {/* Features */}
        <section id="features" className="border-t">
          <div className={cn(WRAP, "py-16")}>
            <Eyebrow>What&apos;s inside</Eyebrow>
            <h2 className="mt-3.5 text-3xl font-semibold tracking-tight text-foreground">
              One workspace, the pieces that matter.
            </h2>
            <p className="mt-3 max-w-xl text-[15px] leading-relaxed text-muted-foreground">
              Each part stands on its own and connects to the rest — so nothing
              lives in a tool nobody opens.
            </p>
            <div className="mt-10 grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
              {FEATURES.map(({ icon: Icon, title, desc }) => (
                <div
                  key={title}
                  className="group rounded-xl border border-input bg-card p-5 transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-[0_16px_40px_-18px_rgb(0_0_0/0.6),0_0_30px_-14px_rgb(62_221_180/0.5)]"
                >
                  <div className="flex h-9 w-9 items-center justify-center rounded-lg border border-primary/25 bg-primary/10 text-primary">
                    <Icon className="h-[18px] w-[18px]" />
                  </div>
                  <div className="mt-4 text-[15px] font-semibold tracking-tight text-foreground">
                    {title}
                  </div>
                  <div className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                    {desc}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Spaces showcase */}
        <section id="spaces" className="border-t">
          <div className={cn(WRAP, "py-16")}>
            <div className="grid items-center gap-12 lg:grid-cols-[0.85fr_1.15fr]">
              <div>
                <Eyebrow>The core idea</Eyebrow>
                <h2 className="mt-3.5 text-3xl font-semibold tracking-tight text-foreground">
                  Organized around Spaces.
                </h2>
                <p className="mt-3 max-w-sm text-[15px] leading-relaxed text-muted-foreground">
                  A Space is a shared home for a team or an effort. It holds the
                  projects, tasks, pages, and discussions that belong together.
                </p>
                <div className="mt-6 flex flex-col gap-3.5">
                  {SPACE_BULLETS.map((x) => (
                    <div key={x.b} className="flex items-start gap-3">
                      <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-md border border-primary/30 bg-primary/15 text-primary">
                        <Check className="h-3 w-3" strokeWidth={2.4} />
                      </span>
                      <span className="text-[13.5px] leading-relaxed text-muted-foreground">
                        <b className="font-semibold text-foreground">{x.b}</b>{" "}
                        {x.t}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
              <SpaceShowcaseMock />
            </div>
          </div>
        </section>

        {/* Secondary strip */}
        <section className="border-t">
          <div className={cn(WRAP, "py-16")}>
            <Eyebrow>And the rest</Eyebrow>
            <h2 className="mt-3.5 text-2xl font-semibold tracking-tight text-foreground">
              Everything else a real team needs.
            </h2>
            <div className="mt-8 grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
              {STRIP.map(({ icon: Icon, label }) => (
                <div
                  key={label}
                  className="flex items-center gap-2.5 rounded-lg border bg-card px-3.5 py-3 transition hover:border-input hover:bg-muted"
                >
                  <Icon className="h-[15px] w-[15px] shrink-0 text-muted-foreground" />
                  <span className="text-[12.5px] font-medium text-foreground">
                    {label}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* How it works */}
        <section id="how" className="border-t">
          <div className={cn(WRAP, "py-16")}>
            <Eyebrow>How it works</Eyebrow>
            <h2 className="mt-3.5 text-3xl font-semibold tracking-tight text-foreground">
              Up and running in three steps.
            </h2>
            <div className="mt-10 grid gap-4 md:grid-cols-3">
              {STEPS.map((s) => (
                <div
                  key={s.n}
                  className="rounded-xl border border-input bg-card p-6"
                >
                  <span
                    className={cn(
                      "flex h-7 w-7 items-center justify-center rounded-md bg-primary font-mono text-[13px] font-medium text-primary-foreground",
                      "shadow-[0_0_16px_-3px_rgb(62_221_180/0.6)]",
                    )}
                  >
                    {s.n}
                  </span>
                  <div className="mt-4 text-[15px] font-semibold tracking-tight text-foreground">
                    {s.title}
                  </div>
                  <div className="mt-1.5 text-[13px] leading-relaxed text-muted-foreground">
                    {s.desc}
                  </div>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Final CTA */}
        <section className={cn(WRAP, "py-16")}>
          <div className="relative overflow-hidden rounded-2xl border border-input bg-card px-6 py-16 text-center">
            <div
              aria-hidden
              className="pointer-events-none absolute inset-x-0 top-0 h-full"
              style={{
                background:
                  "radial-gradient(120% 140% at 50% -20%, rgb(62 221 180 / 0.16), transparent 60%)",
              }}
            />
            <div className="relative">
              <h2 className="mx-auto max-w-xl text-balance text-3xl font-semibold leading-tight tracking-tight text-foreground md:text-4xl">
                Bring the work, and the calm, together.
              </h2>
              <p className="mx-auto mt-3.5 max-w-md text-[15px] leading-relaxed text-muted-foreground">
                Start free with your team today. No bloat, no busywork.
              </p>
              <div className="mt-7 flex justify-center">
                {isAuthenticated ? (
                  <Button asChild size="lg" className={GLOW}>
                    <Link href="/projects">
                      Open your projects <ArrowRight className="h-4 w-4" />
                    </Link>
                  </Button>
                ) : (
                  <Button asChild size="lg" className={GLOW}>
                    <Link href="/signup">{signupCta}</Link>
                  </Button>
                )}
              </div>
            </div>
          </div>
        </section>
      </main>
    </>
  );
};

export default Home;
