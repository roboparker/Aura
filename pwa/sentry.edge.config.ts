// Edge runtime Sentry init — loaded from instrumentation.ts. No-op while the
// DSN is unset. See @/lib/sentryConfig.
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
  sendDefaultPii: false,
})
