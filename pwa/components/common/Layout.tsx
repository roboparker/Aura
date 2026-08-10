import { ReactNode, useState } from "react";
import { useRouter } from "next/router";
import {
  DehydratedState,
  HydrationBoundary,
  QueryClient,
  QueryClientProvider,
} from "@tanstack/react-query";
import { AuthProvider, useAuth } from "@/contexts/AuthContext";
import { ActiveSpaceProvider } from "@/contexts/ActiveSpaceContext";
import { AgentChatProvider } from "@/contexts/AgentChatContext";
import AgentChatDock from "@/components/agents/AgentChatDock";
import TwoFactorRecoveryInterstitial from "@/components/auth/TwoFactorRecoveryInterstitial";
import WaitlistGate from "@/components/auth/WaitlistGate";
import EmailVerificationGate from "@/components/auth/EmailVerificationGate";
import Footer from "./Footer";
import ImpersonationBanner from "./ImpersonationBanner";
import Navbar from "./Navbar";
import Sidebar from "./Sidebar";

/** Target of the skip link and id of the app's single <main> landmark. */
const CONTENT_ID = "content";

/**
 * Public-facing surfaces that keep the marketing footer.
 *
 * Every link in it — Pricing, FAQ, Blog, Guides, the AGPL notice — is an
 * acquisition link, which is noise inside a tool someone has already signed
 * into: it sat below every board, task list and settings pane, and took 28% of
 * total scroll length on `/tasks` at 375px. Signed-in users visiting a
 * marketing page still get it; the app itself doesn't.
 */
const MARKETING_PATHS = new Set(["/", "/pricing", "/faq", "/privacy", "/terms"]);
const MARKETING_PREFIXES = ["/blog", "/guides", "/dev"];

const isMarketingPath = (pathname: string): boolean =>
  MARKETING_PATHS.has(pathname) ||
  MARKETING_PREFIXES.some((p) => pathname === p || pathname.startsWith(`${p}/`));

/**
 * Standalone auth/entry screens that must NEVER render inside the app chrome
 * (sidebar/navbar) — even if a stale `user` lingers from an expired session
 * mid-redirect. Gating on the route (not just auth state) is what stops the
 * sign-in screen from appearing behind the logged-in sidebar.
 */
const CHROME_FREE_PATHS = new Set([
  "/signin",
  "/signup",
  "/forgot-password",
  "/reset-password",
  "/waitlist",
  "/verify-email",
]);

/**
 * Inner shell rendered beneath the auth/space providers. A waitlisted account
 * only ever sees the /waitlist gate, so we render its page chrome-free (no
 * sidebar/navbar) — it reads as a standalone screen rather than the empty app.
 */
const AppShell = ({ children }: { children: ReactNode }) => {
  const { user, isAuthenticated } = useAuth();
  const router = useRouter();

  if (
    user?.waitlisted ||
    user?.emailVerified === false ||
    CHROME_FREE_PATHS.has(router.pathname)
  ) {
    // Still a main landmark — these are standalone screens, but "standalone"
    // isn't "structureless". No skip link: there's no nav to skip past.
    return <main id={CONTENT_ID}>{children}</main>;
  }

  return (
    // Full-width sticky header (ImpersonationBanner above the top bar,
    // pinned together) spanning the whole viewport; below it a row of
    // persistent left sidebar (`md:` and up) + content. The navbar's
    // mobile Sheet handles the small-screen sidebar case. The Sidebar
    // renders nothing for unauthenticated visitors so the marketing/auth
    // screens keep their original full-width layout.
    // Full-height left sidebar (`md:` and up) pinned to the viewport edge;
    // the header (ImpersonationBanner + Navbar) and page content live in the
    // column to its right. The navbar's mobile Sheet handles the small-screen
    // sidebar case. The Sidebar renders nothing for unauthenticated visitors
    // so the marketing/auth screens keep their original full-width layout.
    <div className="flex min-h-screen">
      {/* First focusable element on the page. Visually hidden until focused,
          so keyboard and screen-reader users can jump the ~20 sidebar links
          instead of tabbing through them on every navigation. */}
      <a
        href={`#${CONTENT_ID}`}
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:font-medium focus:text-primary-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
      >
        Skip to content
      </a>
      <Sidebar />
      <div className="flex flex-1 min-w-0 flex-col">
        {/* Above the page's sticky layers (board title/tabs bar = z-30,
            column header = z-20) so a short page scrolling up can't push them
            over the navbar. Stays below portalled popovers/menus (z-50). */}
        <header className="sticky top-0 z-40">
          <ImpersonationBanner />
          <Navbar />
        </header>
        <div className="flex flex-1 min-h-0 flex-col">
          {/* The single main landmark for the whole app. Pages must not render
              their own — a nested <main> is invalid and exposes two landmarks.
              tabIndex={-1} so the skip link can move focus here, not just
              scroll to it. */}
          <main id={CONTENT_ID} tabIndex={-1} className="flex-1 focus:outline-none">
            {children}
          </main>
          {/* Marketing chrome, so only on marketing surfaces. An
              unauthenticated visitor can only reach public pages anyway (app
              routes bounce to sign-in), so they always keep it. */}
          {(!isAuthenticated || isMarketingPath(router.pathname)) && <Footer />}
        </div>
      </div>
    </div>
  );
};

const Layout = ({
  children,
  dehydratedState,
}: {
  children: ReactNode;
  dehydratedState: DehydratedState;
}) => {
  const [queryClient] = useState(() => new QueryClient());

  return (
    <QueryClientProvider client={queryClient}>
      <HydrationBoundary state={dehydratedState}>
        <AuthProvider>
          <ActiveSpaceProvider>
            <AgentChatProvider>
              <AppShell>{children}</AppShell>
              {/* The agent chat dock (#827). Deliberately outside AppShell so
                  it survives navigation — it's a dock, not a route — and
                  renders nothing until an agent is opened, so it costs no DOM
                  for sessions that never use one. */}
              <AgentChatDock />
              {/* Forced modal that appears the moment the API signals
                  this session signed in with a backup code. See
                  TwoFactorRecoveryInterstitial for the flow. Renders
                  nothing when not pending so it costs no DOM. */}
              <TwoFactorRecoveryInterstitial />
              {/* Keeps a waitlisted account pinned to the /waitlist gate
                  wherever it tries to navigate. Renders nothing for normal
                  users. */}
              <WaitlistGate />
              {/* Keeps an unverified account pinned to the /verify-email
                  gate until it confirms its email. Renders nothing for
                  verified users. */}
              <EmailVerificationGate />
            </AgentChatProvider>
          </ActiveSpaceProvider>
        </AuthProvider>
      </HydrationBoundary>
    </QueryClientProvider>
  );
};

export default Layout;
