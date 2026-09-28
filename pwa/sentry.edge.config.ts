// Sentry init for the Next.js edge runtime (middleware, edge routes).
// Dark-launched like the server config — blank DSN = disabled.
import * as Sentry from "@sentry/nextjs";

import { sentryDataCollection } from "@/lib/sentryDataCollection";

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (dsn) {
  Sentry.init({
    dsn,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || undefined,
    release: process.env.NEXT_PUBLIC_SENTRY_RELEASE || undefined,
    tracesSampleRate: Number(process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? "0.1"),
    // Restrictive v10-equivalent baseline — see lib/sentryDataCollection.ts.
    dataCollection: sentryDataCollection,
    // Structured Logs: mirror edge-runtime console.* into Sentry Logs. Additive.
    // (v11 opts logs in via the integration; `enableLogs` no longer exists.)
    integrations: [
      Sentry.consoleLoggingIntegration({ levels: ["log", "info", "warn", "error"] }),
    ],
  });
}
