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

### Testing

Every PR must keep CI green, which includes the test suites:

- **API** — PHPUnit (`bin/phpunit`). New endpoints, services, and behaviour
  changes need tests under `api/tests/`. A **minimum line-coverage floor** is
  enforced on PRs: the *Tests* CI job runs the suite with coverage
  (`XDEBUG_MODE=coverage … --coverage-clover`) and then
  [`api/bin/coverage-check.php`](../api/bin/coverage-check.php) fails the build
  if `api/src` line coverage drops below the floor (currently 84%; measured
  ~85%). Dev/test seeders under `src/DataFixtures` are excluded from coverage
  (see `api/phpunit.xml.dist`). To check locally:

  ```bash
  docker compose exec -e XDEBUG_MODE=coverage php php -d memory_limit=-1 \
    bin/phpunit --coverage-clover var/coverage.xml
  docker compose exec php php bin/coverage-check.php var/coverage.xml 84
  ```
- **PWA** — Vitest over the framework-free logic in `pwa/lib`
  (`pnpm test`). A **minimum-coverage floor** is enforced on PRs via
  `pnpm test:coverage`: the modules listed in `coverage.include` in
  [`pwa/vitest.config.ts`](../pwa/vitest.config.ts) must stay above the
  configured thresholds (lines/statements ≥ 85%, functions ≥ 90%,
  branches ≥ 75%). When you add a new pure-logic helper under `pwa/lib`,
  add it (and its `*.test.ts`) to that allowlist so the gate keeps it
  covered. Component/integration coverage lives in the Playwright **e2e**
  suite rather than the unit layer.

If your change is genuinely untestable in these layers (pure infra/config),
say so in the PR description.

See `docs/developer/branching-and-releases.md` for the full branching and release strategy.

## License

Madori is licensed under the [GNU AGPL-3.0](../LICENSE). Because the project is
also offered under separate commercial licenses, all contributions are
accepted under our [Contributor License Agreement](../CLA.md): by opening a
pull request, you agree to its terms for your contributions. You keep
ownership of your work — the CLA grants the maintainer a license (including
the right to relicense) so the dual-licensing model can work.
