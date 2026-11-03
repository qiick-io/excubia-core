# CLAUDE.md — Excubia (`qiick/excubia-core`)

Guidance for Claude Code in this repository. Read it fully before any change.

## Project

Excubia is the open-source (MIT) health check for Laravel apps in the qiick suite. Composer package `qiick/excubia-core`, CLI binary `bin/excubia`, plus the Artisan alias `php artisan excubia:check` through an auto-discovered service provider. It reads lockfiles and manifests and reports PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, next-major blockers and an ordered upgrade plan.

- Source of truth: `docs/SPEC.md` and `docs/ARCHITECTURE.md`. Roadmap: `docs/ROADMAP.md`. Decisions D1–D13 and A1–A2 are final.
- Anything marked **[PROPOSAL]** or labelled `needs-decision` is not approved: do not build it.
- Opt-in runtime monitoring (Phase B, approved 2026-11-02) lives in this package, enabled via config, and reaches `excubia-app` only through the push protocol (`docs/SPEC.md` sections 12–13). There is no `excubia-probe`. The multi-project dashboard is the separate repo `qiick-io/excubia-app`, which consumes this package.
- Display name is "Excubia" alone, never "qiick Excubia", in user-facing strings and docs.

## Stack

- PHP `^8.2` package floor (installs in Laravel 12–13 apps; Laravel 11 dropped by D19); Pest and CI run on PHP 8.3+.
- `symfony/console`, `symfony/http-client`, `composer/semver`. No Guzzle, no Laravel runtime dependency (`illuminate/*` is only suggested).
- Dev: Pest 4, Pint (pinned `<1.31`), PHPStan / Larastan. No npm, no Vitest, no `php artisan test`.

## Layout

```
bin/excubia            standalone CLI entry, no Laravel boot
src/Checks/            one *Check per check id, implements HealthCheckContract
src/Contracts/         *Contract interfaces (HttpClientContract is faked in tests)
src/Data/              readonly *Data value objects
src/Enums/             *Enum (SeverityEnum, FindingStatusEnum, ReportFormatEnum, ExitCodeEnum)
src/Services/          *Service (runner, upgrade plan, snapshot, data-source API clients, cache)
src/Parsers/           *Parser for composer.lock/json, package-lock.json, Node version files
src/Renderers/         table / json / markdown *Renderer
src/Registries/        HealthCheckRegistry (order, --only / --skip)
src/Configuration/     ExcubiaConfigurationLoader (excubia.json + excubia.schema.json)
src/Console/           symfony/console commands used by bin/excubia
src/Laravel/           service provider + Artisan alias
src/Exceptions/        *Exception
tests/Arch|Unit|Feature|Fixtures|Support   Pest; Arch/NamingArchitectureTest.php enforces naming
docs/                  SPEC, ARCHITECTURE, ROADMAP, report-schema.json
```

## Commands

```bash
composer test      # Pest
composer lint      # Pint --test
composer analyse   # PHPStan
php bin/excubia check --no-banner        # dogfood on this repo
```

Run all three before every push. CI (`.github/workflows/ci.yml`) runs PHP 8.3/8.4/8.5 × lowest/stable, Pint, PHPStan, Pest with coverage, a `src/Checks` coverage gate and a dogfood run.

## Code conventions ("Codice Perfetto")

Also in `.cursor/rules/code-craftsmanship.mdc` and `.cursor/rules/testing.mdc`; follow them.

- Simplest thing that works (YAGNI). No unrequested abstractions, dependencies or files. Mark deliberate shortcuts with a `ponytail:` comment naming the ceiling and the upgrade path.
- `declare(strict_types=1);` in every PHP file.
- Every class ends with its role suffix: `Check`, `Contract`, `Data` (readonly), `Enum`, `Service`, `Parser`, `Renderer`, `Registry`, `Loader`, `Command`, `Exception`. No models, controllers, policies, jobs or actions in this package.
- Full words only (no `cfg`, `res`, `req`, `ctx`, `idx`). Booleans start with `is`, `has`, `can` or `should`.
- Curly braces always; a blank line around control-flow blocks. Strict DRY.
- English PHPDoc on classes and non-trivial methods: why, array shapes, side effects, `@throws`. No line-by-line noise.
- No `dd`, `dump`, `var_dump` or `env()`.
- Tests: behavior-focused Pest, no real network (fake `HttpClientContract` + recorded responses in `tests/Fixtures/Responses/`). Never delete or weaken a test; add coverage for each behavior change. Keep `NamingArchitectureTest` green.
- Privacy is a feature: only package names and versions leave the machine toward public data sources. The only other destination is the user's own `excubia-app`, through the opt-in push (`docs/SPEC.md` section 13). Never send file contents, absolute paths, hostnames or `.env` values. No telemetry to qiick or third parties.
- Report JSON shape (`docs/report-schema.json`), check ids and exit codes are public contracts: change them only when the issue says so, and update schema, README and CHANGELOG together.

## Git and PR workflow

One issue → one branch → one PR. Never commit directly to `develop` or `main`.

1. Pick an open issue (or ask Luca to open one). Read its acceptance criteria.
2. `git checkout develop && git pull --ff-only`
3. `git checkout -b <type>/<issue>-<kebab-slug>` — types: feat, fix, refactor, chore, docs, test, perf, ci, build, security.
4. Commit with Conventional Commits in English (`feat(checks): add …`). Small, focused commits.
5. `composer lint && composer analyse && composer test`, then `git push -u origin HEAD`.
6. `gh pr create --base develop --title "<conventional title>" --body "Closes #<issue>…"` using `.github/pull_request_template.md`.
7. `gh pr merge --auto --squash` and move on to the next issue. Do not wait for CI; if it goes red, fix it in the same branch.
8. `git checkout develop && git pull --ff-only && git branch -d <branch>`.

`develop` is the integration branch (PR required, no review). `main` is releases only (1 review).

## Identity and security

- Commit and push only as **LucaGiardi20** (`git config user.name` = "Luca Giardi"). Check `gh api user -q .login` returns `LucaGiardi20` before any `gh` write.
- Never use the `lucagiarditailorventures` account, never add a `Co-authored-by` trailer for it, never run `gh auth switch`.
- Never use `--admin`, `--no-verify`, force-push to shared branches, or bypass branch protection.
- Never commit secrets, tokens, `.env` files, personal phone numbers or email addresses.

## Ask Luca first

Stop and ask before any of these:

- Releases: version bumps, CHANGELOG release sections, tags, GitHub Releases, `develop` → `main` merges, Packagist publishing (`.cursor/rules/no-auto-release.mdc`). Only when Luca says "rilascia".
- Adding, removing or upgrading a Composer dependency; changing the PHP floor.
- Changing public contracts (CLI options, exit codes, check ids, report JSON, `excubia.json` schema) beyond what the issue states.
- Anything outside SPEC/ARCHITECTURE scope, ambiguous acceptance criteria, or work that needs secrets, accounts or paid services.
- Editing branch protection, repo settings, CI required checks, or GitHub Actions secrets.

When blocked on one issue, leave a short comment on the issue explaining what is needed and move to the next.
