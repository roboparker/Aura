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

module.exports = nextConfig
