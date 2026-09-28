/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  output: 'standalone',
  // @sentry/nextjs (via @sentry/server-utils) loads meriyah's ESM build at
  // runtime through an external require. Next's standalone output-file-tracing
  // only picks up meriyah.cjs, so `node server.js` crashes with "Cannot find
  // module .../meriyah/dist/meriyah.mjs" (every task container is then
  // unhealthy). Force the whole meriyah dist into the trace so the symlinked
  // copies under @apm-js-collab/code-transformer and @sentry/server-utils
  // resolve. Keyed broadly since the Sentry load happens at server startup
  // (instrumentation), not on a single route.
  //
  // Same gap for Sentry v11's channel-based instrumentation: it installs a
  // Node loader via Module.register() pointing at
  // @sentry/server-runtime-injection/build/esm/vendored/.../hook.js — a
  // runtime-only reference the tracer can't follow. Without it the SDK logs
  // "Failed to register diagnostics-channel injection hooks" and server-side
  // spans are silently dropped (errors still report). Include its ESM build.
  outputFileTracingIncludes: {
    '/**/*': [
      './node_modules/.pnpm/meriyah@*/node_modules/meriyah/dist/**',
      './node_modules/.pnpm/@sentry+server-runtime-injection@*/node_modules/@sentry/server-runtime-injection/build/esm/**',
    ],
  },
  // Next.js dev tools indicator (dev-only) pinned to the bottom-right corner.
  devIndicators: {
    position: 'bottom-right',
  },
  async redirects() {
    // Account management consolidated into the Settings shell. Server-side
    // redirects so old links/bookmarks (and the post-login fallback) resolve
    // instantly without a client hydration hop. `/settings` matches the bare
    // path only; `/settings/*` sub-routes pass through.
    return [
      { source: '/account', destination: '/settings/profile', permanent: false },
      { source: '/settings', destination: '/settings/profile', permanent: false },
    ]
  },
}

// Sentry v11 moved withSentryConfig to the /config entry point; the root
// @sentry/nextjs export no longer carries it.
// eslint-disable-next-line @typescript-eslint/no-require-imports -- CommonJS config file
const { withSentryConfig } = require('@sentry/nextjs/config')

// Wrap for Sentry's build-time instrumentation. Source-map upload is opt-in:
// it only runs when SENTRY_AUTH_TOKEN (+ org/project) are set at build time, so
// a normal build without them just skips the upload. Runtime error/perf capture
// is driven by the sentry.*.config + instrumentation files (dark-launched on a
// blank NEXT_PUBLIC_SENTRY_DSN).
module.exports = withSentryConfig(nextConfig, {
  silent: true,
  // v11 replacement for the removed top-level `disableLogger`.
  webpack: { treeshake: { removeDebugLogging: true } },
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,
  sourcemaps: { disable: !process.env.SENTRY_AUTH_TOKEN },
})
