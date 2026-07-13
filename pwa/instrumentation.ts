// Next.js instrumentation hook. register() runs once per server/edge runtime
// boot and loads the matching Sentry init; onRequestError forwards server-side
// errors (SSR, API routes, RSC) to Sentry. Both no-op while the DSN is unset.
import * as Sentry from "@sentry/nextjs"

export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") {
    await import("./sentry.server.config")
  }
  if (process.env.NEXT_RUNTIME === "edge") {
    await import("./sentry.edge.config")
  }
}

export const onRequestError = Sentry.captureRequestError
