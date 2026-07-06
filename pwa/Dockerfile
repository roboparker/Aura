#syntax=docker/dockerfile:1


# Versions
FROM node:lts AS node_upstream


# Base stage for dev and build
FROM node_upstream AS base

WORKDIR /srv/app

# Pin pnpm to the version recorded in pwa/package.json's `packageManager`
# field. Using `pnpm@latest` here would override that pin with whatever
# was uploaded to npm at CI time — pnpm 11.x in particular requires a
# `pnpm setup` step to add `/root/.local/share/pnpm/bin` to PATH, and
# `corepack prepare --activate pnpm@latest` doesn't run it. Result: a
# rebuild against an unrelated PR fails at this step the next time pnpm
# ships a major. Pinning explicitly keeps the image reproducible.
RUN npm install -g corepack@latest && \
    corepack enable && \
	corepack prepare --activate pnpm@9.1.3 && \
	pnpm config -g set store-dir /.pnpm-store

# Next.js collects completely anonymous telemetry data about general usage.
# Learn more here: https://nextjs.org/telemetry
# Delete the following line in case you want to enable telemetry during dev and build.
ENV NEXT_TELEMETRY_DISABLED=1


# Development image
FROM base AS dev

EXPOSE 3000
ENV PORT=3000
ENV HOSTNAME=localhost

CMD ["sh", "-c", "pnpm install; pnpm dev"]


FROM base AS builder

COPY --link pnpm-lock.yaml ./
RUN pnpm fetch --prod

COPY --link . .

# Sentry (PWA) is configured at build time via NEXT_PUBLIC_* baked into the
# client bundle. The DSN is injected from a CI secret so it stays OUT of the
# public repo; the release is the commit SHA. Both default empty, so forks and
# local builds that don't pass them build with Sentry OFF.
ARG NEXT_PUBLIC_SENTRY_DSN=""
ARG NEXT_PUBLIC_SENTRY_RELEASE=""
ENV NEXT_PUBLIC_SENTRY_DSN=$NEXT_PUBLIC_SENTRY_DSN
ENV NEXT_PUBLIC_SENTRY_RELEASE=$NEXT_PUBLIC_SENTRY_RELEASE

RUN	pnpm install --frozen-lockfile --offline --prod && \
	pnpm run build


# Production image, copy all the files and run next
FROM node_upstream AS prod

WORKDIR /srv/app

ENV NODE_ENV=production
# Delete the following line in case you want to enable telemetry during runtime.
ENV NEXT_TELEMETRY_DISABLED=1

RUN addgroup --gid 1001 nodejs; \
	adduser --uid 1001 --gid 1001 nextjs

COPY --from=builder --link /srv/app/public ./public

# Set the correct permission for prerender cache
RUN mkdir .next; \
	chown nextjs:nodejs .next

# Automatically leverage output traces to reduce image size
# https://nextjs.org/docs/advanced-features/output-file-tracing
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/standalone ./
COPY --from=builder --link --chown=1001:1001 /srv/app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

ENV PORT=3000
ENV HOSTNAME="0.0.0.0"

CMD ["node", "server.js"]
