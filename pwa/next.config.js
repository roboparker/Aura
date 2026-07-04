/** @type {import('next').NextConfig} */
const nextConfig = {
  reactStrictMode: true,
  output: 'standalone',
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
