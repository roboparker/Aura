// Sentry init for the browser. Dark-launched: blank NEXT_PUBLIC_SENTRY_DSN =
// disabled (dev / CI never send). Session Replay is intentionally OFF to
// conserve the free-tier quota and keep the client bundle lean. See
// docs/developer/monitoring.md.
import * as Sentry from "@sentry/nextjs";

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
    sendDefaultPii: false,
    // Structured Logs: mirror browser console.* into Sentry Logs. Additive —
    // messages still print to the console as usual.
    enableLogs: true,
    integrations: [
      Sentry.consoleLoggingIntegration({ levels: ["log", "info", "warn", "error"] }),
    ],
  });
}

// Instrument client-side navigations for performance tracing.
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
