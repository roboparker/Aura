const { withSentryConfig } = require('@sentry/nextjs')

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

// Wrap with Sentry. The SDK itself is gated on NEXT_PUBLIC_SENTRY_DSN (blank =
// disabled), so this wrapper is inert at runtime until a DSN is configured.
// Source-map upload only runs when SENTRY_AUTH_TOKEN is present at build time —
// the committed default has no token, so builds succeed without one. To upload
// maps, pass SENTRY_ORG / SENTRY_PROJECT / SENTRY_AUTH_TOKEN as build args/env.
module.exports = withSentryConfig(nextConfig, {
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,
  // Quiet the plugin's build logs outside CI.
  silent: !process.env.CI,
  // Skip map upload unless an auth token is configured (keeps default builds green).
  sourcemaps: { disable: !process.env.SENTRY_AUTH_TOKEN },
  // Tree-shake Sentry's debug logger out of the client bundle.
  disableLogger: true,
  // Widen upload to catch client files hosted from a CDN sub-path, if enabled.
  widenClientFileUpload: true,
})
