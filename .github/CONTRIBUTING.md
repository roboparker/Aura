# Contributing to Aura

Thanks for your interest in contributing!

## Reporting Bugs

Before submitting a bug report:

1. Check [existing issues](https://github.com/roboparker/Aura/issues) to avoid duplicates
2. Use the [bug report template](https://github.com/roboparker/Aura/issues/new?template=bug_report.yml)
3. Include as much detail as possible (OS, PHP version, Node version, steps to reproduce)

## Branching

Create a branch from `main` using the appropriate prefix:

| Prefix      | Purpose                               | Example                   |
|-------------|---------------------------------------|---------------------------|
| `feature/`  | New functionality                     | `feature/add-user-entity` |
| `fix/`      | Bug fixes                             | `fix/login-validation`    |
| `chore/`    | Maintenance, deps, config             | `chore/upgrade-deps`      |
| `docs/`     | Documentation changes                 | `docs/update-api-guide`   |
| `refactor/` | Code restructuring (no behavior change) | `refactor/extract-service` |

Use lowercase `kebab-case` for branch names.

## Pull Requests

1. Base your changes on the `main` branch
2. Fill in the PR template
3. Make sure all CI checks pass
4. Add tests for new functionality or bug fixes
5. Commits will be squashed on merge

### Coding Standards

- **PHP**: Follow [Symfony coding standards](https://symfony.com/doc/current/contributing/code/standards.html)
- **TypeScript/React**: Follow the existing patterns in the codebase

See `docs/branching-and-releases.md` for the full branching and release strategy.

## License

By submitting a PR, you agree to license your contribution under the [MIT license](../LICENSE).
