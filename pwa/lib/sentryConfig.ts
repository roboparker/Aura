// Shared Sentry init values for the browser, server, and edge runtimes
// (see docs/developer/sentry.md).
//
// DSN + sample rate come from NEXT_PUBLIC_* env vars baked in at build time
// (pwa/.env.production for the prod image). The DSN is public — it ships in
// the browser bundle just like the Umami website ID — so it is safe to commit.
// A blank DSN disables the SDK, so local dev, tests, and any build without a
// configured DSN send nothing.

export const sentryDsn = process.env.NEXT_PUBLIC_SENTRY_DSN || undefined

export const sentryEnabled = Boolean(sentryDsn)

export const sentryEnvironment =
  process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT || process.env.NODE_ENV || "production"

function parseRate(raw: string | undefined, fallback: number): number {
  if (raw === undefined || raw === "") return fallback
  const n = Number(raw)
  return Number.isFinite(n) ? n : fallback
}

// Fraction of transactions sampled for performance tracing (0..1). Defaults to
// 10%; set NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE=0 to capture errors only.
export const sentryTracesSampleRate = parseRate(
  process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE,
  0.1,
)
