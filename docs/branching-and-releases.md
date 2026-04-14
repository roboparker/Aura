# Branching & Release Strategy

## Overview

Aura follows **GitHub Flow** — a lightweight, branch-based workflow where `main` is always deployable and all changes arrive via pull requests. Releases are tagged with **date-based build numbers**.

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

4. Create a GitHub release from the tag (optional but recommended)

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
