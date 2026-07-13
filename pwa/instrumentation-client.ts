// Browser-side Sentry init. Next.js auto-loads this file for the client
// runtime (the successor to the old sentry.client.config.ts). No-op while the
// DSN is unset — see @/lib/sentryConfig.
import * as Sentry from "@sentry/nextjs"

import {
  sentryDsn,
  sentryEnabled,
  sentryEnvironment,
  sentryTracesSampleRate,
} from "@/lib/sentryConfig"

Sentry.init({
  dsn: sentryDsn,
  enabled: sentryEnabled,
  environment: sentryEnvironment,
  tracesSampleRate: sentryTracesSampleRate,
  // Privacy-first: never attach IP/cookies/user identity to events.
  sendDefaultPii: false,
})

// Instruments client-side navigations for tracing.
export const onRouterTransitionStart = Sentry.captureRouterTransitionStart
