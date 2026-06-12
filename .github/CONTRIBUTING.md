# Contributing to Madori

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

See `docs/developer/branching-and-releases.md` for the full branching and release strategy.

## License

Madori is licensed under the [GNU AGPL-3.0](../LICENSE). Because the project is
also offered under separate commercial licenses, all contributions are
accepted under our [Contributor License Agreement](../CLA.md): by opening a
pull request, you agree to its terms for your contributions. You keep
ownership of your work — the CLA grants the maintainer a license (including
the right to relicense) so the dual-licensing model can work.
