// Next.js server instrumentation entrypoint — loads the Sentry server/edge
// init for the matching runtime. Guarded configs mean this is inert without a
// DSN. See docs/developer/monitoring.md.
import * as Sentry from "@sentry/nextjs";

export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    await import("./sentry.server.config");
  }
  if (process.env.NEXT_RUNTIME === "edge") {
    await import("./sentry.edge.config");
  }
}

// Report errors thrown in nested React Server Components / route handlers.
export const onRequestError = Sentry.captureRequestError;
