# Blog

A public, **file-based** blog for tips, tricks, and progress updates, built
as an SEO surface. Posts are markdown files committed to the repo (so content
is versioned, diffable, and PR-reviewed) and rendered as static HTML with full
meta tags + a sitemap. There is **no backend** for the blog — nothing in the
database, no admin UI.

## Authoring a post

Add a markdown file under `pwa/content/blog/<slug>.md` with frontmatter:

```markdown
---
title: How we ship Madori
slug: how-we-ship          # optional; defaults to the filename
description: A short SEO description for search results + social cards.
date: 2026-06-21            # ISO or YYYY-MM-DD; drives ordering + published date
author: Robert Parker      # optional byline
draft: false               # true = hidden in production, visible only in dev
# ogImage: /blog/how-we-ship.png   # optional social-share image
---

Body in markdown. Don't repeat the title as an `# H1` — it's rendered from
frontmatter.
```

To publish: commit the file and deploy (the post is baked in at build time).
Drafts (`draft: true`) render locally (`pnpm dev`) for preview but are
excluded from production builds, the listing, the static paths, and the
sitemap.

### Social images

Commit an image (ideally 1200×630) under `pwa/public/blog/` and reference it
as `ogImage: /blog/<file>` in frontmatter. The blog pages emit it as an
absolute `og:image` / `twitter:image` using the build-time site origin.

## How it renders

Everything is resolved at **build time** — the production image only ships
`public/` + `.next`, never the `content/` tree, so nothing reads markdown at
request time.

- `pwa/lib/blog.ts` — **server-only** loader (`getAllPosts`,
  `getPostSummaries`, `getPostBySlug`, `siteUrl`) that reads
  `pwa/content/blog/*.md` and parses frontmatter (a small dependency-free
  parser). It imports `node:fs`, so it must be referenced **only** from
  `getStaticProps`/`getStaticPaths` (which Next strips from the client
  bundle) — using it in a component would pull `fs` into the browser build
  and fail under Turbopack.
- `pwa/lib/blogTypes.ts` — client-safe types + `formatBlogDate()`, free of
  any `node:` imports so the page components can use them directly.
- `pwa/pages/blog/index.tsx` — SSG listing (`getStaticProps`).
- `pwa/pages/blog/[slug].tsx` — SSG detail (`getStaticPaths` + `getStaticProps`,
  `fallback: false`) with the full SEO `<head>`: description, canonical,
  OpenGraph, Twitter card, `article:published_time`.
- `pwa/scripts/gen-blog-sitemap.mjs` — a predev/prebuild hook (chained after
  `sync-docs`) that writes `pwa/public/sitemap.xml` from the post frontmatter.
  The file is git-ignored (a build artifact); `public/robots.txt` advertises it.
- The footer links to `/blog`.

Absolute URLs (canonical, OG image, sitemap) come from
`NEXT_PUBLIC_SITE_URL`, committed in `pwa/.env.production`
(default `http://localhost:3000` for dev).

## Why file-based

Chosen over a DB-backed CMS because the blog is dev-authored and the priority
is version control — posts get git history, diffs, and review like code. The
trade-off is that publishing requires a deploy (content is static, built at
release time) rather than an instant in-app save.
