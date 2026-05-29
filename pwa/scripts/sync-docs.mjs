#!/usr/bin/env node
// Copies the repo-root `docs/` tree into `pwa/.content/docs/` so the
// markdown viewer routes can read it via `fs` at build time without
// reaching outside the package. Idempotent and tolerant of a missing
// source — Docker prod builds run this against the already-staged copy.
import { cp, stat, mkdir, rm } from "node:fs/promises";
import { resolve, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const dst = resolve(here, "..", ".content", "docs");

// Source candidates, in priority order:
//  1. `../../docs` — repo root when running on the host (`pnpm dev` /
//     `pnpm build` from `pwa/`) or inside the Docker dev container with
//     the `./docs:/srv/docs` bind mount in compose.override.yaml.
//  2. `/srv/docs` — same Docker dev bind mount, addressed absolutely.
const candidates = [
  resolve(here, "..", "..", "docs"),
  "/srv/docs",
];

let src = null;
for (const c of candidates) {
  try {
    const s = await stat(c);
    if (s.isDirectory()) {
      src = c;
      break;
    }
  } catch {
    // not found — try the next candidate
  }
}

if (!src) {
  // Likely running inside the Docker prod build context where the docs
  // were already staged by an earlier step (or just not available). The
  // page reads from `.content/docs/` and renders an empty list if absent.
  console.warn(
    "[sync-docs] No source `docs/` directory found; leaving .content/docs/ as-is.",
  );
  process.exit(0);
}

// `cp({ recursive, force })` overwrites existing entries but won't remove
// files the source no longer has — wipe first to keep deletions in sync.
await rm(dst, { recursive: true, force: true });
await mkdir(dst, { recursive: true });
await cp(src, dst, { recursive: true });
console.log(`[sync-docs] Synced ${src} -> ${dst}`);
