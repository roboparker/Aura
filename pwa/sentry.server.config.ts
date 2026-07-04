// Sentry init for the Next.js Node server runtime (SSR / API routes).
// Dark-launched: a blank NEXT_PUBLIC_SENTRY_DSN leaves the SDK disabled, so
// dev / CI never send events. The DSN is public (baked at build), so the same
// value serves client + server. See docs/developer/monitoring.md.
import * as Sentry from "@sentry/nextjs";

const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (dsn) {
  Sentry.init({
    dsn,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || undefined,
    release: process.env.NEXT_PUBLIC_SENTRY_RELEASE || undefined,
    tracesSampleRate: Number(process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? "0.1"),
    sendDefaultPii: false,
  });
}
