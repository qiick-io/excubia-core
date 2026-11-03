# Excubia — Specification v0.1.0

> Status: **APPROVED by Luca on 2026-10-31** (all recommendations in section 10 accepted). This file and `docs/ARCHITECTURE.md` are the single source of truth for v0.1.0. Anything not written here is out of scope; new ideas become `needs-decision` proposal issues, not code.
>
> Sources: `knowledge/projects/qiick/README.md` (roadmap Step 1, formerly Pervigilium / Specula), `knowledge/projects/qiick/excubia-brand.md`, `knowledge/projects/qiick/research/2026-10-22-beyond-qiick-ideas.md` (Addendum 2 "Laravel Health Check: MVP scope"), `knowledge/conventions/code-quality.md` (Codice Perfetto).
> Decisions D1–D13 and A1–A2 are final (section 10). Anything still marked **[PROPOSAL]** is not approved and must not be built; open a `needs-decision` issue instead.

## 1. Product

**Excubia** is an open-source (MIT) health check for Laravel projects. It reads a project's lockfiles and manifests and reports, in one place: PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, blockers for the next Laravel major, and a suggested upgrade order.

Why it exists (knowledge base): `composer audit` only checks CVEs; laravelversions.com only shows EOL; Laravel Shift is paid per upgrade; laravel/vet reviews package code (complementary); LaraPlugins scores single packages, not your project; Enlightn is archived. Nothing combines EOL, CVEs, abandoned packages, upgrade order and a summary in one report.

It is roadmap **Step 1, Phase A** of the qiick suite. Projects install only `qiick/excubia-core` with a normal `composer require` (not `--dev`): the package stays lightweight, with minimal dependencies and zero runtime cost while monitoring is disabled. It ships the CLI plus **opt-in** live/runtime monitoring enabled via config. There is no separate `qiick/excubia-probe` package. Phase B (uptime, connectors, and that opt-in monitoring, connected to the self-hosted multi-project dashboard `qiick-io/excubia-app` or later qiick Cloud) is roadmap Step 4. It was **not** part of the v0.1.0 spec; it was approved on 2026-11-02 and is specified in sections 12 and 13.

Branding: display name is **"Excubia"** alone (package `qiick/excubia-core`, repo `qiick-io/excubia-core`). Do not prefix the product name with "qiick" in headings, CLI banner, user-facing strings, or docs. Part of the qiick suite; suite affiliation stays in prose and package/repo ids. Never the q tile next to the qiick wordmark, Vigil Blue accent, tagline placeholder "Health checks for Laravel apps." (TBD).

### Target users
1. Laravel developers and freelancers maintaining one or more apps.
2. Agencies with many client apps that need a quick "how bad is it?" report before quoting an upgrade.
3. CI pipelines (GitHub Actions) that should fail on critical findings.
4. AI coding agents (Cursor, Claude Code) that run the CLI and read JSON/Markdown output (Agent-First rule).

## 2. Scope v0.1.0

In scope:
- A framework-agnostic PHP **core** (parsers, checks, data-source clients, cache, report model).
- A **CLI** shipped in the same Composer package: `vendor/bin/excubia check` (standalone, no app boot) and, when installed in a Laravel app, the alias `php artisan excubia:check` (auto-discovered service provider). See Decision D1.
- Inputs: `composer.lock` (required), `composer.json` (optional, for PHP constraint and direct vs. transitive deps), `package-lock.json` (optional), `package.json` / `.nvmrc` / `.node-version` (optional, Node version).
- Output formats: `table` (default, human), `json`, `markdown`. `sarif` is deferred to v0.3.0 (D6).
- Local file cache of data-source responses with TTLs, and `--offline` mode.
- CI: own GitHub Actions workflow + first reusable workflow in `qiick-io/.github` (roadmap Step 1 explicitly includes "`qiick-io/.github` reusable workflows").
- Release to Packagist as `qiick/excubia-core` v0.1.0.

### Explicit NON-goals for v0.1.0
- No uptime monitoring, multi-region probes, connectors, or opt-in runtime monitoring (Phase B, Step 4; ships later inside `qiick/excubia-core`, not a separate probe package; dashboard is `qiick-io/excubia-app`).
- No paid features, no qiick Cloud, no accounts, no login, no telemetry.
- No web scanner in v0.1.0 (it is milestone v0.2.0, section 9; Decision D2).
- No code changes to the scanned project (read-only; no auto-fix, no `composer update`).
- No source-code analysis (that is laravel/vet's job); only manifests/lockfiles.
- No AI summary in v0.1.0 (D4; no metered services without Luca's approval).
- No GitHub App / PR comments / scheduled multi-repo monitoring / badges (knowledge base "Later, Pro/CI mode").

## 3. Health checks

Severity levels: `critical` > `high` > `medium` > `low` > `info`. Each finding has: `checkId`, `severity`, `status` (`pass` | `warn` | `fail` | `skipped`), `subject` (package or runtime), `message`, `fixHint`, `evidenceUrl`, and `advisoryId` (so an ignore rule can match one advisory).

| ID | Name | Inspects | Data source | Fail | Warn | Pass | Fix hint |
|---|---|---|---|---|---|---|---|
| `php-eol` | PHP end of life | Resolved PHP version: `config.platform.php` > `require.php` lower bound in `composer.json` > `platform` in `composer.lock` | endoflife.date `php` | Security support ended (**critical**) | Active support ended, security support ends within 180 days (**medium**) | Supported | "Upgrade PHP to X.Y (supported until DATE)." |
| `laravel-eol` | Laravel end of life | Locked `laravel/framework` version | endoflife.date `laravel` | Security fixes ended (**critical**) | Bug fixes ended or security ends within 180 days (**medium**) | Supported | "Upgrade to Laravel N; security fixes for your version end DATE." |
| `node-eol` | Node end of life | `.nvmrc`, `.node-version`, `package.json` `engines.node` | endoflife.date `nodejs` | EOL (**high**) | Maintenance ends within 180 days (**low**) | Supported / `skipped` if not declared | "Move to Node LTS N." (Brand copy mentions PHP/Node versions; D7) |
| `composer-advisories` | Known vulnerabilities (Composer) | Every locked package + version (incl. dev, flag `--no-dev` to exclude) | Packagist security advisories API (GitHub Advisory DB + FriendsOfPHP) | Any advisory affecting the locked version; severity mapped from advisory (critical/high/medium/low) | — | No advisories | "Update vendor/package to ≥ fixed version (advisory ID)." |
| `npm-advisories` | Known vulnerabilities (npm) | Every package in `package-lock.json` (v2/v3) | OSV.dev batch query | Any vulnerability affecting the locked version (severity from OSV/CVSS, default medium) | — | None / `skipped` if no lockfile | "Update package to ≥ fixed version." |
| `composer-abandoned` | Abandoned packages | Packagist `abandoned` flag of each locked package | Packagist metadata | Abandoned, direct dependency (**high**) | Abandoned, transitive (**medium**) | Not abandoned | "Replace with `<suggested replacement>`" (from Packagist), or "no replacement suggested" |
| `composer-stale` | Stale packages | Last release date (Packagist) and GitHub `archived` / last push | Packagist metadata + GitHub API (unauthenticated, optional `GITHUB_TOKEN`) | Repository archived (**high**) | No release for > 24 months (**low**, threshold configurable) | Recent release | "Consider a maintained alternative." Demo case: `jenssegers/agent` |
| `laravel-next-blocker` | Next-major blockers | For each direct dependency requiring `illuminate/*` or `laravel/framework`: does its **latest** release allow the next Laravel major (today `^13`)? | Packagist metadata | Latest release does not allow next major (**medium**) | Allows it only in a pre-release (**low**) | Allows it / not Laravel-dependent | "No release supports Laravel N yet; watch / replace / fork." |
| `laravel-debug-mode` | Debug mode | `app.debug` and the environment of the booted app (Artisan only) | Booted app | `app.debug` true outside `local` / `testing` (**high**) | — | Debug off, or `local` / `testing` | "Set APP_DEBUG=false outside local and testing, then run php artisan config:cache." |
| `laravel-production-caches` | Production caches | Config, route and event cache of the booted app (Artisan only), one row per cache (subjects `config-cache`, `route-cache`, `event-cache`) | Booted app | — | Cache missing in `production` (**low**) | All cached / `skipped` outside `production` | "Run php artisan config:cache (or php artisan optimize) on deploy." |
| `laravel-pending-migrations` | Pending migrations | Migration files vs. the migrations table of the default connection (Artisan only) | Migrator | Migrations not run (**medium**); count and up to three names | — | All run / `skipped` if the database cannot be read | "Run php artisan migrate --force as part of the deploy." |
| `laravel-failed-jobs` | Failed jobs | Count and oldest `failed_at` of the failed job store (Artisan only) | Failed job provider | — | Failed jobs exist (**medium**; **high** when the oldest is 24 hours old or more, configurable) | None / `skipped` with the `null` driver or an unreadable store | "Inspect them with php artisan queue:failed, then retry or remove them." |
| `laravel-scheduler-heartbeat` | Scheduler heartbeat | Cache key `excubia:scheduler-heartbeat`, written every minute by the scheduled `excubia:heartbeat` command (Artisan only) | Default cache store | — | Heartbeat older than 10 minutes (configurable) or never written (**medium**) | Recent heartbeat / `skipped` if the cache cannot be read | "Run php artisan schedule:run every minute." |
| `laravel-maintenance-mode` | Maintenance mode | Maintenance mode of the booted app (Artisan only) | Booted app | — | App is down (**medium**) | App is up | "Run php artisan up when the maintenance is over." |
| `upgrade-plan` | Upgrade order | Combines the above into an ordered list: security fixes → runtime EOL (PHP) → blockers → Laravel major → stale/abandoned replacements | Derived | — (always `info`) | — | — | Ordered, numbered steps |

Notes:
- "180 days" and "24 months" are defaults, configurable (section 5).
- `upgrade-plan` is a report section, never changes the exit code.
- Node version precedence is `.nvmrc` > `.node-version` > `package.json` `engines.node`.
- The next Laravel major is the locked `laravel/framework` major plus one, not a fixed `^13`.
- The six `laravel-*` posture checks (#73, approved for 1.0.0-beta on 2026-11-03) need the booted app, so only `php artisan excubia:check` and `excubia:push` run them: the Artisan command reads the posture through the container into `LaravelPostureData` and attaches it to the snapshot. `vendor/bin/excubia` cannot boot the app and reports all six as `skipped` with "requires Artisan" (`skipReason` `not-applicable`). They are read-only except the heartbeat key, add no per-request hook, and their findings carry only booleans, counts, ages and names. The service provider schedules `excubia:heartbeat` every minute; it stores the Unix time under `excubia:scheduler-heartbeat` in the default cache store. The posture checks do not feed the upgrade plan.
- Not in v0.1.0 (D8): `env-example-sync` (keys in `.env.example` vs. `config/*.php` `env()` calls) and `qiick-recommendations` (suggest `qiick/*` replacements) are mentioned in the knowledge base only as ideas ("better as a Health Check check", "Specula Health Check Recommendations"). They become `needs-decision` proposal issues, not code.

## 4. CLI UX

```
vendor/bin/excubia check [path] [options]
php artisan excubia:check [options]          # same engine, path = base_path()

Options:
  --format=table|json|markdown|sarif   Output format (default: table; sarif = SARIF 2.1.0, v0.3.0, D6)
  --output=FILE                  Write report to FILE instead of stdout
  --fail-on=critical|high|medium|low|none   Minimum severity that makes the exit code 1 (default: high)
  --only=ID,ID                   Run only these checks
  --skip=ID,ID                   Skip these checks
  --no-dev                       Ignore require-dev / packages-dev
  --offline                      Use cache only; checks without cached data are `skipped`
  --no-cache                     Bypass the cache
  --config=FILE                  Config file (default: ./excubia.json if present)
  --no-banner                    Hide the ASCII banner (brand/excubia/cli/banner.txt)
  --strict                       Exit 3 when a data-source failure skipped a check (also `"strict": true` in excubia.json)
  -q | -v | -vv                  Quiet / verbose / debug (HTTP calls listed)

vendor/bin/excubia cache:clear
vendor/bin/excubia --version

vendor/bin/excubia push [path] [--config=FILE] [--strict]   # run the checks, send the report to excubia-app (section 13)
php artisan excubia:push [--config=FILE] [--strict]
php artisan excubia:push-runtime [--config=FILE]   # send buffered runtime aggregates (opt-in, section 13)
```

Exit codes:
- `0` no finding at or above `--fail-on`
- `1` at least one finding at or above `--fail-on`
- `2` usage or configuration error (missing `composer.lock`, broken or unparseable `composer.lock`, invalid config, unknown check id). The error message says what is wrong.
- `3` data-source failure (network down and no cache, or an API error) that skipped a check — only with `--strict` or `"strict": true` (approved for 1.0.0-beta, 2026-11-03). Without it, affected checks are `skipped` with the reason as the finding message, and the exit code ignores them. The error message names each check and its failing data source. Deliberate skips never give `3`: `--offline` without a cached response, `--only` / `--skip`, Laravel checks on a non-Laravel project, a disabled check, or a row removed by an ignore rule.
- Precedence: `2` > `1` > `3`, so CI still sees real findings first. `--strict` can only turn strict mode on: the file key cannot be switched off from the CLI, and `--strict` wins over `"strict": false`.
- The JSON report marks every `skipped` finding with `skipReason`: `data-source-failure`, `offline`, or `not-applicable` (optional field, `schemaVersion` stays 1).
- Structured values for excubia-app (#92, approved for 1.0.0-beta 2026-11-03, optional fields, `schemaVersion` stays 1): top-level `generatedAt` (UTC `Y-m-d\TH:i:s\Z`), `excubiaVersion` (as `--version`) and `project` (`php`, `laravel`, `node` versions, each nullable; no name, path or hostname); per finding `fingerprint` (sha256 of `checkId`, `subject`, `advisoryId` joined by NUL, the SARIF partial fingerprint), `advisoryId` when present and `details` with check-specific keys documented per check id in `docs/report-schema.json` (versions, package names, advisory ids, dates, counts and ages only). Table, Markdown and SARIF output do not change.

Table output: grouped by severity, coloured (no colour when not a TTY or `NO_COLOR`), summary line `3 critical · 2 high · 5 medium · 1 low` and the upgrade plan at the end. JSON output schema is versioned (`"schemaVersion": 1`) and documented in `docs/report-schema.json`.

## 5. Config file

`excubia.json` at the project root (JSON so it works without Laravel and is easy for agents to edit):

```json
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "includeDev": true,
  "checks": {
    "composer-stale": { "staleAfterMonths": 24 },
    "php-eol": { "warnDaysBeforeEol": 180 },
    "laravel-eol": { "warnDaysBeforeEol": 180 },
    "node-eol": { "enabled": true },
    "laravel-scheduler-heartbeat": { "staleAfterMinutes": 10 },
    "laravel-failed-jobs": { "highSeverityAfterHours": 24 }
  },
  "ignore": [
    { "checkId": "composer-advisories", "subject": "vendor/package", "advisoryId": "GHSA-xxxx", "reason": "Not reachable", "until": "2027-01-31" }
  ],
  "cache": { "directory": null, "ttlHours": 24 }
}
```

Optional top-level `"strict": true` is the same as `--strict` (section 4); default `false`.

Optional `"privacy": { "skipPackages": ["acme/*", "@acme/*"] }` (#108): package name globs (`*` any characters including `/`, `?` exactly one, case-insensitive, whole name; no other glob syntax) that are never sent to a public data source, on top of the packages the lockfile already marks private (section 7). Default `[]`.

Rules: every `ignore` entry needs a `reason`; an expired `until` makes the finding active again and prints a warning. Unknown keys are a config error (exit 2).

## 6. Supported versions

- Runtime of the tool: PHP **^8.2** so it installs as a dev dependency in Laravel 12 and 13 apps (D3; the qiick app skeleton is PHP 8.4/8.5, but a CLI must reach older projects). Pest and the CI test jobs run on PHP 8.3 and newer only. There is no PHP 8.2 Pest job.
- Artisan integration: Laravel 12, 13 (D19). Scanning Laravel 11 projects with the standalone CLI keeps working; they are reported as end of life.
- Scanned projects: any Composer project; Laravel-specific checks are `skipped` when `laravel/framework` is not locked.
- Lockfiles: `composer.lock` (Composer 2), `package-lock.json` lockfileVersion 2 and 3. `yarn.lock` / `pnpm-lock.yaml` are non-goals for v0.1.0.

## 7. Security & privacy

- Only package **names and versions** (and runtime versions) leave the machine, and only to the public APIs listed in section 3. No file contents, no `.env`, no source code, no paths, no hostnames.
- **Private packages are never sent (#108, decided 2026-11-03).** Only packages the lockfile shows as served by the public registries are sent to Packagist, OSV.dev or GitHub:
  - Composer: public only when the `composer.lock` entry has `notification-url` `https://packagist.org/downloads/` and its dist type is not `path` or `artifact`. Path and artifact dists, VCS repositories, Satis, Private Packagist (another `notification-url`) and entries without a `notification-url` are private.
  - npm: public only when `resolved` starts with `https://registry.npmjs.org/` and the entry is not `link: true`. `file:` and VCS dependencies, links, workspace members (no `resolved`) and other registries are private.
  - `privacy.skipPackages` globs (section 5) mark more packages private, for example a private package published on the public npm registry.

  Each check that would send names (`composer-advisories`, `npm-advisories`, `composer-abandoned`, `composer-stale`, `laravel-next-blocker`) adds one `skipped` row with subject `private packages`, `skipReason` `not-applicable` and the message "N private packages not sent to <source>: name, name." The names come from the local lockfile and stay in the report (they reach only the user's own `excubia-app` through the opt-in push); a check with no other finding reports `skipped`, never `fail`, so `--strict` and the exit code are unchanged.
- No telemetry, no analytics, no phone-home to qiick or any third party. The only exception is the opt-in push to the user's own `excubia-app` (section 13), which is off until the user configures an app URL and token.
- Opt-in runtime monitoring (`runtime.enabled` in `excubia.json`, off by default, #75) sends only these aggregates per UTC minute, each with `count`, `errorCount`, `totalMs`, `maxMs` and its `sampleRate` (`docs/runtime-schema.json`):
  - requests: route name (or `unnamed`), HTTP method, status class (`2xx`…);
  - slow queries: SQL text without bindings, with quoted string literals replaced by `?`, and the connection name;
  - jobs: job class, queue and connection names, `processed` or `failed`;
  - scheduled tasks: description, or `artisan <command>` without the PHP and Artisan binaries, or `Closure`; paths relative to the project (a file name outside it), secrets masked as in exception messages; `finished`, `failed` or `skipped`;
  - outgoing HTTP (Laravel's HTTP client): host and status class.

  Never sent, and never written to the local buffer: URLs and query strings, request or response bodies, headers, cookies, users, IP addresses, query bindings, exception messages, `.env` values or absolute paths.
- Opt-in exception capture (`exceptions.enabled` in `excubia.json`, off by default and independent of `runtime.enabled`, #77, D17) hooks one `reportable()` callback on Laravel's exception handler. It sees only what the app reports (`dontReport` applies), returns nothing so the app's own reporting is unchanged, never throws into the app, and skips the classes in `exceptions.ignore`. Per occurrence group (same fingerprint, context and environment) it sends exactly (`docs/exception-schema.json`):
  - `class` (anonymous classes as `Parent@anonymous`), `fingerprint`, and the masked `message` (at most 1000 characters);
  - `frames`, innermost first, at most 50: `file` relative to the project root (`vendor/<package>/…` for dependencies, the bare file name outside the project), `line`, `function` (`Class::method`, closures without their line), `inApp`. Never arguments or local variables;
  - `previous`: classes of the chained exceptions (at most 5), without their messages;
  - `context`: `request` + route name (`unnamed` without one), `job` + job class, `command` + Artisan command name without arguments, or `unknown`;
  - `environment` (`app.env`), `count`, `firstSeen` and `lastSeen`.

  **Masking (D17)**, applied before anything is written to the local buffer: first the user's `exceptions.maskPatterns` (→ `[masked]`), then absolute paths (project root removed, other paths reduced to the file name), URL credentials (removed) and query strings and fragments (→ `?[query]`), quoted SQL literals when the message carries SQL (→ `?`), database `Host` / `Socket` / `Port` / `Database` details (→ `[masked]`), JWTs (`[jwt]`), `Bearer` / `Basic` credentials (`[token]`), `password=`, `secret:`, `token=`, `api_key=`… values (`[masked]`), emails (`[email]`), IPv4 and IPv6 addresses (`[ip]`), UUIDs (`[uuid]`), hex strings of 32+ characters and 20+ character strings mixing letters and digits such as API keys (`[token]`), card-like runs of 13–19 digits and any number longer than four digits (`[number]`). Query exceptions are rebuilt from the driver message, the connection name and the SQL without bindings. Never sent: request payloads, headers, cookies, users, IP addresses, query bindings, `.env` values or absolute paths.
- `GITHUB_TOKEN` is optional, read from the environment only, never printed or written to the cache or report.
- The response cache defaults to a per-user directory in the system temp directory and is skipped when that directory is a symlink, owned by another user or writable by others. The threat model of every data path is `docs/SECURITY-MODEL.md`.
- All HTTP over TLS with timeouts (connect 5 s, total 15 s) and limited retries with backoff; respects `HTTPS_PROXY`.
- The tool never writes to the scanned project except `--output` and never executes project code (no app boot for `vendor/bin/excubia`). The Artisan commands run inside the already booted app; the posture checks read it without writing, except the scheduler heartbeat cache key, and never report `.env` values, DSNs, hostnames, paths or exception messages.
- `SECURITY.md` with private vulnerability reporting.

## 8. Testing, CI, release

- Pest 4 unit + feature tests; Pest architecture tests for Codice Perfetto (strict types, role suffixes, readonly `*Data`, no `dd`/`dump`/`env()`).
- Fixtures: sample `composer.lock` / `composer.json` / `package-lock.json` sets in `tests/Fixtures/Projects/*` (healthy Laravel 13 app, outdated Laravel 10 app, abandoned/archived packages incl. `jenssegers/agent`, npm vulnerable lockfile, non-Laravel PHP project, broken lockfile).
- No real network in tests: recorded API responses in `tests/Fixtures/Responses/*`, an HTTP client contract with a fake.
- Static analysis: Larastan/PHPStan level max, Pint (`laravel` preset), Rector dry-run optional.
- CI (`.github/workflows/ci.yml`): Pest, Pint, and PHPStan on PHP 8.3–8.5 × lowest/stable deps. The package floor stays PHP ^8.2. No PHP 8.2 Pest job. Pest line coverage ≥ 90 % for `src/Checks` and ≥ 90 % for all of `src/` (Laravel integration tests included), enforced by `.github/assert-coverage.php`. The reusable workflow `qiick-io/.github/.github/workflows/php-package-ci.yml` remains the intended home; until that repository is available the jobs live in this repo. SHA-pinned actions.
- Dogfooding: CI runs `bin/excubia check --fail-on=critical` on the repo itself.
- Versioning: SemVer, Conventional Commits, tags `v0.1.0`, GitHub Releases with notes; Packagist `qiick/excubia-core` (D5: vendor `qiick` must be claimed on Packagist).
- Branches: `main` (releases), `develop` (integration), feature branches `<type>/<issue>-<slug>`; squash merges only.

## 9. Web scanner — postponed, no milestone (D14)

The text below is kept as the idea of record. It is not planned for any version until Luca decides otherwise.


Roadmap Step 1 includes "CLI + free zero-signup web scanner". Proposed as the next milestone, on the same core:
- Page on `qiick.io/excubia` (or `qiick.io/tools/*`, D2) where users paste or drop `composer.lock` / `package-lock.json`.
- Lockfiles are **parsed in the browser**; only names and versions are sent (knowledge base rule).
- Laravel backend endpoint using the same core with queues, cache, rate limits (also the load study for the utilities suite).
- Abuse protections: per-IP rate limits, payload size caps (e.g. 2 000 packages), cached results, no storage of submitted data beyond the cache TTL, Impressum + privacy policy (Germany).
- Domain verification is only needed for Phase B (probing URLs), not for lockfile scans.

## 10. Decisions (approved by Luca on 2026-10-31)

1. **D1 CLI form:** standalone `vendor/bin/excubia` + Artisan alias `excubia:check`, or Artisan only? **Decided:** both, one engine (works in CI and non-Laravel projects; KB says "Artisan command or Composer binary").
2. **D2 Web scanner:** v0.2.0 after the CLI, and where (`qiick.io/excubia` vs `qiick.io/tools/laravel-health`)? **Decided:** v0.2.0, at `qiick.io/excubia` (product routes live inside qiick.io).
3. **D3 Tool runtime PHP:** ^8.2 or ^8.4 (fleet skeleton)? **Decided:** ^8.2, to reach Laravel 11/12 apps.
4. **D4 AI summary (KB check 7):** in v0.1.0? **Decided:** no; ship a deterministic upgrade plan now, add an opt-in AI summary later with the user's own key (no metered qiick service).
5. **D5 Packagist vendor:** claim `qiick` on Packagist with LucaGiardi20? **Decided:** yes, before the first tag.
6. **D6 SARIF output** (GitHub code scanning): v0.1.0 or later? **Decided:** later (v0.3.0 with the GitHub Action), JSON/Markdown first.
7. **D7 Node EOL check:** keep `node-eol` in v0.1.0 (brand copy promises "PHP/Node versions")? **Decided:** yes, it is small.
8. **D8 Extra checks** `env-example-sync` and `qiick-recommendations`: **Decided:** not in v0.1.0; open as proposals.
9. **D9 Config format:** `excubia.json` or `excubia.php`? **Decided:** JSON (no Laravel boot, agent-friendly, JSON Schema).
10. **D10 Default `--fail-on`:** `high` or `critical`? **Decided:** `high`.
11. **D11 Stale threshold:** 24 months without a release? **Decided:** 24 months, configurable.
12. **D12 Tagline:** keep placeholder "Health checks for Laravel apps." for v0.1.0? **Decided:** yes until the website copy is final.
13. **D13 Trademark similarity search** (TMview/EUIPO/WIPO for "Excubia") before the first public release? **Decided:** yes, before tagging v0.1.0 (open item in the brand file).

## 11. Clarifications (Luca, 2026-10-31)

These five answers are final.

1. The package floor stays PHP ^8.2. Pest and the CI test jobs run on PHP 8.3 and newer only. There is no PHP 8.2 Pest job.
2. Node version precedence is `.nvmrc`, then `.node-version`, then `package.json` `engines.node`.
3. The next Laravel major is the locked `laravel/framework` major plus one. It is not a fixed `^13`.
4. A broken or unparseable `composer.lock` is exit code 2, with a clear error message.
5. `advisoryId` is a finding field so an ignore rule can match that advisory.

## 12. Decisions (Luca, 2026-11-02)

These decisions are final. They extend section 10 and replace D2 where they disagree.

14. **D14 Web scanner:** the public zero-signup scanner (section 9, issues #7–#9) is postponed with no milestone. It is not needed for 1.0.0. `HealthCheckRunnerService::runForSnapshot()` stays as the core seam for it.
15. **D15 Phase B approved:** projects send data to the user's own self-hosted `qiick-io/excubia-app`. Nothing goes to qiick or third parties. Each kind of data is a separate opt-in.
16. **D16 Push, not pull:** projects push to the app with `excubia push` (section 13). The app never reads project directories and never polls projects. The push ships in v0.2.0 so `excubia-app` v0.1.0 can use it.
17. **D17 Exceptions before 1.0.0:** opt-in exception capture (#77) ships before 1.0.0, with messages masked before sending.
18. **D18 Health endpoint:** an opt-in endpoint compatible with the Oh Dear application health JSON (#74) is approved at low priority. It complements the push for external monitors; it is not the channel to `excubia-app`. Contract: `docs/health-endpoint.md` (`healthEndpoint` keys, `EXCUBIA_HEALTH_SECRET`, serves the last stored Artisan run).
19. **D19 Laravel 11 dropped (Luca, 2026-11-03):** the Artisan integration supports Laravel 12 and 13 from 1.0. Laravel 11 is past security support and Composer blocks its releases by default, so it cannot be tested in CI. The standalone CLI still scans Laravel 11 projects.

## 13. Push protocol (approved 2026-11-02)

- **Command:** `vendor/bin/excubia push [path]` and `php artisan excubia:push` run the checks and send the report. Reports are sent daily from the Laravel scheduler and from CI after deploy.
- **Configuration:** app URL and project token from `excubia.json` (`"app": {"url": "…", "token": "…"}`) or `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN`; the environment wins over the file. The URL is the app's base URL. The token is never printed, cached or written to a report.
- **Endpoint:** `POST {url}/api/reports` with the envelope as JSON. The app answers 2xx with a JSON body when it stored the report (or already had that `batchId`), 401/403 for a wrong or revoked token.
- **Exit codes of `push`:** `0` sent (findings do not change it), `2` configuration error (missing URL or token, plain HTTP to a host other than localhost, missing `composer.lock`, invalid config), `3` the app rejected the token, answered an error, or could not be reached. With `--strict` (or `"strict": true`), a check skipped because a data source failed also gives `3`, and the report is **not sent**, so the app never stores an incomplete report as complete. Each case prints a message saying which.
- **Transport:** HTTPS only (plain HTTP only for localhost), `Authorization: Bearer <token>`, the timeouts and retries of section 7.
- **Envelope:** JSON with `protocolVersion` (1), a unique `batchId` (UUID v4, new per run, kept across HTTP retries) so a retried send is stored once, `sentAt` (UTC, `Y-m-d\TH:i:s\Z`), and the payload (`report`, following `docs/report-schema.json`). Documented in `docs/push-schema.json`. The envelope is a public contract, frozen at 1.0.0 (#76).
- **Runtime data and exceptions** (#75, #77, opt-in): written to a local buffer, never sent during a request, sent in batches by the scheduler about every minute. When the app is unreachable the buffer keeps data up to a size cap and drops the oldest entries; it never slows the project down.
- **Runtime endpoint** (#75): `php artisan excubia:push-runtime`, scheduled every minute by the provider when `runtime.enabled` is true, sends `POST {url}/api/runtime` with the same URL, token, transport and retries as the report. Envelope: `protocolVersion` 1, `type: "runtime"`, `batchId`, `sentAt` and `runtime` (`docs/runtime-schema.json`), documented as `$defs.runtimeEnvelope` in `docs/push-schema.json`. The buffer (`storage/excubia/runtime/`, `runtime.bufferMaxBytes`, default 1 MiB) is claimed into batch files that carry their `batchId`, so a batch is resent with the same id until the app answers 2xx; only then is it deleted. Aggregates are additive: the app sums entries with the same type, bucket, sample rate and dimensions, also across batches. Exit codes as for `push`: `0` sent or nothing to send (or disabled), `2` configuration error, `3` token rejected, app error or unreachable (the data is kept).
- **Exceptions endpoint** (#77): the same `php artisan excubia:push-runtime` run sends the exception buffer (`storage/excubia/exceptions/`, `exceptions.bufferMaxBytes`, default 1 MiB) to `POST {url}/api/exceptions` when `exceptions.enabled` is true, also with runtime monitoring off; the provider schedules the command every minute when either opt-in is on. Envelope: `protocolVersion` 1, `type: "exceptions"`, `batchId`, `sentAt` and `exceptions` (`{"groups": […]}`, `docs/exception-schema.json`), documented as `$defs.exceptionsEnvelope` in `docs/push-schema.json`. Same batch files, `batchId` reuse until 2xx, cap and exit codes as the runtime batches; a failure on one buffer does not stop the other. Groups are additive: the app adds `count` and widens `firstSeen` / `lastSeen` for the same fingerprint, also across batches.
- **What is sent:** the report (package names and versions, runtime versions, findings); for runtime data route names, job and command names, durations and counts, SQL without bindings; for exceptions class, fingerprint, masked message, and stack frames with paths relative to the project root. Never request payloads, headers, cookies, users, IP addresses, `.env` values or absolute paths.
- **Stale projects:** the app marks a project stale when no report arrives within a configurable window, which also reveals a stopped scheduler.

