# Branching & Release Strategy

## Overview

Aura follows **GitHub Flow** — a lightweight, branch-based workflow where `main` is always deployable and all changes arrive via pull requests. Releases are tagged with **date-based build numbers**.

There are exactly **two long-lived branches**:

| Branch       | Role                                                                                                          |
|--------------|---------------------------------------------------------------------------------------------------------------|
| `main`       | Development trunk and the repo's default branch. All PRs target it; it is always deployable.                   |
| `production` | What the production server runs. Updated **only** by merging a `main → production` "promote" PR. Every push to it triggers the automated deploy (see below). |

### Promoting to production

```bash
gh pr create --base production --head main \
  --title "Promote main to production" \
  --body "Deploys everything merged since the last promote."
```

Merging that PR is the deploy button. The diff of the promote PR is exactly what is about to go live. Use a **merge commit** for promote PRs (not squash) so `production` keeps the same commit SHAs as `main` and stays fast-forwardable.

### What the deploy does

On push to `production`, [`.github/workflows/deploy.yml`](../../.github/workflows/deploy.yml):

1. Builds the api + pwa production images in CI and pushes them to GHCR, tagged with the commit SHA and `latest`.
2. SSHes to the server (dedicated deploy key, pinned host key, secrets scoped to the branch-restricted `production` GitHub environment) and runs [`scripts/deploy.sh`](../../scripts/deploy.sh), which:
   - raises a **maintenance page** (Caddy serves a static 503 while the flag file exists),
   - takes a **pre-deploy database + media backup** (same retention pool as the nightly cron — newest `MAX_BACKUPS` kept),
   - pulls the new images and swaps the containers (migrations auto-run from the entrypoint),
   - waits for the stack to report healthy, then lowers the maintenance page.
3. Verifies the site from the outside (`https://madori.app` + `/signup-status`).

On any failure the maintenance page **stays up** and the workflow fails loudly — fix forward, or roll back.

### Rolling back

Run the **Deploy** workflow manually (workflow_dispatch) with `image_tag` set to a previously deployed commit SHA. The build step is skipped; the server just re-pulls those images and swaps. Keep migrations backward-compatible so the previous code runs against the current schema.

## Workflow

```mermaid
gitDiagram
    commit id: "main"
    branch feature/add-books
    commit id: "feat: add Book entity"
    commit id: "test: add Book tests"
    checkout main
    merge feature/add-books id: "Merge PR #12"
    commit id: "2026.04.12.1" tag: "2026.04.12.1"
    branch fix/validation-bug
    commit id: "fix: required field check"
    checkout main
    merge fix/validation-bug id: "Merge PR #13"
    commit id: "2026.04.13.1" tag: "2026.04.13.1"
```

## Branch Naming

All branches are short-lived and created from `main`. Use the following prefixes:

| Prefix      | Purpose                          | Example                        |
|-------------|----------------------------------|--------------------------------|
| `feature/`  | New functionality                | `feature/add-user-entity`      |
| `fix/`      | Bug fixes                        | `fix/login-validation`         |
| `chore/`    | Maintenance, deps, config        | `chore/upgrade-symfony-7.3`    |
| `docs/`     | Documentation changes            | `docs/update-api-guide`        |
| `refactor/` | Code restructuring (no behavior change) | `refactor/extract-service` |

**Rules:**
- Use lowercase with hyphens (`kebab-case`)
- Keep names short but descriptive
- Delete branches after merging

## Pull Request Workflow

1. **Create a branch** from `main`
2. **Make commits** with clear, descriptive messages
3. **Open a PR** against `main`
4. **CI checks must pass** (tests, linting, E2E)
5. **Squash and merge** into `main`
6. **Delete the branch**

### PR Requirements
- Descriptive title
- Fill in the PR template (bug fix, new feature, tests pass, etc.)
- All CI checks green
- Squash commits into a single commit on merge

## Releases

### Build Number Format

Releases use **date-based build numbers**: `YYYY.MM.DD.N`

- `YYYY` — four-digit year
- `MM` — two-digit month
- `DD` — two-digit day
- `N` — sequence number for that day (starts at 1)

**Examples:**
- `2026.04.12.1` — first release on April 12, 2026
- `2026.04.12.2` — second release on the same day
- `2026.05.01.1` — first release on May 1, 2026

### Creating a Release

1. Ensure `main` is in a deployable state (CI green)
2. Determine the build number (check latest tag for today's date)
3. Tag and push:

```bash
# Check the latest tag
git tag -l "$(date +%Y.%m.%d).*" --sort=-v:refname | head -1

# Tag the release (increment N if a tag already exists today)
git tag 2026.04.12.1
git push origin 2026.04.12.1
```

4. The **Changelog workflow** (`.github/workflows/changelog.yml`) fires automatically on tag push:
   - Regenerates `CHANGELOG.md` from conventional commits via [git-cliff](https://github.com/orhun/git-cliff) and pushes the update to `main`.
   - Creates a GitHub Release whose body is the just-cut section of the changelog.
   No manual step is required for either; the workflow uses `cliff.toml` at the repo root for grouping rules. Commits that don't follow `feat:`/`fix:`/`security:`/`perf:`/`refactor:` are dropped from the user-visible changelog (housekeeping like `chore:`, `docs:`, `ci:` is intentionally excluded).

### What Triggers a Release

Not every merge needs a release. Create a release when:
- A meaningful feature or fix is merged and ready for deployment
- A security patch needs to ship immediately
- A batch of related changes is complete

## Hotfix Process

For urgent fixes to a deployed release:

```mermaid
gitDiagram
    commit id: "main"
    commit id: "2026.04.12.1" tag: "2026.04.12.1"
    branch fix/critical-bug
    commit id: "fix: patch security issue"
    checkout main
    merge fix/critical-bug id: "Merge hotfix"
    commit id: "2026.04.12.2" tag: "2026.04.12.2"
```

1. Create a `fix/` branch from `main`
2. Apply the fix with tests
3. Open a PR, get CI green
4. Merge to `main`
5. Tag immediately with the next build number

## Summary

| Aspect              | Approach                          |
|---------------------|-----------------------------------|
| Default branch      | `main` (always deployable)        |
| Branch lifetime     | Short-lived (hours to days)       |
| Merge strategy      | Squash and merge                  |
| Commit messages     | Clear and descriptive             |
| Versioning          | Date-based build numbers `YYYY.MM.DD.N` |
| Release mechanism   | Git tags + GitHub releases        |
| CI triggers         | Push to `main`, all PRs           |
