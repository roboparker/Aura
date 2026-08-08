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
  outputFileTracingIncludes: {
    '/**/*': ['./node_modules/.pnpm/meriyah@*/node_modules/meriyah/dist/**'],
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

// eslint-disable-next-line @typescript-eslint/no-require-imports -- CommonJS config file
const { withSentryConfig } = require('@sentry/nextjs')

// Wrap for Sentry's build-time instrumentation. Source-map upload is opt-in:
// it only runs when SENTRY_AUTH_TOKEN (+ org/project) are set at build time, so
// a normal build without them just skips the upload. Runtime error/perf capture
// is driven by the sentry.*.config + instrumentation files (dark-launched on a
// blank NEXT_PUBLIC_SENTRY_DSN).
module.exports = withSentryConfig(nextConfig, {
  silent: true,
  disableLogger: true,
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,
  sourcemaps: { disable: !process.env.SENTRY_AUTH_TOKEN },
})
