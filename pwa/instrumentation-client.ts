// Sentry init for the browser. Dark-launched: blank NEXT_PUBLIC_SENTRY_DSN =
// disabled (dev / CI never send). Session Replay is intentionally OFF to
// conserve the free-tier quota and keep the client bundle lean. See
// docs/developer/monitoring.md.
import * as Sentry from "@sentry/nextjs";

import { sentryDataCollection } from "@/lib/sentryDataCollection";

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (dsn) {
  Sentry.init({
    dsn,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || undefined,
    release: process.env.NEXT_PUBLIC_SENTRY_RELEASE || undefined,
    tracesSampleRate: Number(process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? "0.1"),
    // Session Replay off (both sampling rates 0) — errors still report fully.
    replaysSessionSampleRate: 0,
    replaysOnErrorSampleRate: 0,
    // Restrictive v10-equivalent baseline — see lib/sentryDataCollection.ts.
    dataCollection: sentryDataCollection,
    // Structured Logs: mirror browser console.* into Sentry Logs. Additive —
    // messages still print to the console as usual. (v11 opts logs in by
    // using a logging integration; there is no `enableLogs` flag any more.)
    integrations: [
      Sentry.consoleLoggingIntegration({ levels: ["log", "info", "warn", "error"] }),
    ],
  });
}

// Instrument client-side navigations for performance tracing.
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
