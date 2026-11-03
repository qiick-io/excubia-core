<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="Excubia: Health checks for Laravel apps, from the command line" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# Excubia

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)
[![CI](https://img.shields.io/github/actions/workflow/status/qiick-io/excubia-core/ci.yml?branch=main&label=CI)](https://github.com/qiick-io/excubia-core/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777BB4?logo=php&logoColor=white)](./composer.json)

> **Status:** early development (0.x). The CLI works, but the CLI options, report JSON, check ids and `excubia.json` schema may still change before 1.0.0. See [`docs/ROADMAP.md`](./docs/ROADMAP.md).

**Releases:** see [GitHub Releases](https://github.com/qiick-io/excubia-core/releases) and [CHANGELOG.md](./CHANGELOG.md).

**Excubia** is the open-source (MIT) Laravel health-check CLI of the [qiick](https://qiick.io) suite. Package [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core) reads lockfiles and manifests and reports, in one place: PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, blockers for the next Laravel major, and a suggested upgrade order.

**Who it is for:** Laravel developers and freelancers; agencies that need a quick “how bad is it?” report before quoting an upgrade; CI pipelines that should fail on critical findings; AI coding agents that run the CLI and consume JSON/Markdown (Agent-First).

| | |
| --- | --- |
| **Spec** | [`docs/SPEC.md`](./docs/SPEC.md) · [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md) |
| **Install** | `composer require qiick/excubia-core` |
| **Security** | [`SECURITY.md`](./SECURITY.md) |
| **Issues** | [GitHub Issues](https://github.com/qiick-io/excubia-core/issues) |
| **Release** | [GitHub Releases](https://github.com/qiick-io/excubia-core/releases) |
| **Packagist** | [qiick/excubia-core](https://packagist.org/packages/qiick/excubia-core) |

### Features

- **Eight health checks plus an upgrade plan** — EOL, advisories, abandoned/stale packages, next-major blockers, ordered upgrade plan
- **Standalone CLI** — `vendor/bin/excubia` (no app boot) plus Artisan alias `php artisan excubia:check`
- **Formats** — `table` (default), `json`, `markdown`, `sarif` (SARIF 2.1.0 for GitHub code scanning)
- **CI-friendly** — `--fail-on`, exit codes, optional `--offline` cache
- **GitHub Action** — `qiick-io/excubia-core@v1` uploads findings to GitHub code scanning
- **Config** — project-root `excubia.json` with JSON Schema
- **Push** — `excubia push` sends the report to your own self-hosted `excubia-app` (opt-in)
- **Runtime monitoring and exception capture** — separate opt-ins for Laravel apps, sent to your `excubia-app`, masked and never slowing the app down

**Non-goals (v0.1.x):** no web scanner yet (planned v0.2.0), no uptime or runtime monitoring yet (Phase B, planned as opt-in inside excubia-core), no auto-fix of the scanned project, no source-code analysis, no telemetry.

## Requirements

| Requirement | Notes |
| --- | --- |
| **PHP ^8.2** | Package floor so it installs as a dev dependency in Laravel 12 / 13 apps. |
| **Composer 2** | Scans `composer.lock` (required). |
| **Laravel 12–13** (optional) | Needed only for the Artisan alias via package discovery. |

Pest / CI jobs run on PHP 8.3+. The Artisan integration (`excubia:check`, `excubia:push`) is tested in CI on Laravel 12 and 13 with PHP 8.3, 8.4 and 8.5. Laravel 11 is not supported for the Artisan integration: it is past security support and Composer blocks its releases by default (D19). The standalone CLI still scans Laravel 11 projects and reports them as end of life. Scanned projects may be any Composer app; Laravel-specific checks are skipped when `laravel/framework` is not locked.

## Install

```bash
composer require qiick/excubia-core
```

Binary: `vendor/bin/excubia`.

In a Laravel app with the package discovered:

```bash
php artisan excubia:check
```

## Quick usage

```bash
vendor/bin/excubia check
vendor/bin/excubia check --format=json
vendor/bin/excubia check --format=markdown --output=report.md
vendor/bin/excubia check --format=sarif --output=report.sarif
vendor/bin/excubia check --fail-on=critical
vendor/bin/excubia check --only=php-eol,composer-advisories
vendor/bin/excubia check --skip=node-eol --no-dev
vendor/bin/excubia check --offline
vendor/bin/excubia check --strict
vendor/bin/excubia cache:clear
vendor/bin/excubia --version
```

Key options: `--format=table|json|markdown|sarif`, `--output=FILE`, `--fail-on=critical|high|medium|low|none` (default `high`), `--only` / `--skip`, `--no-dev`, `--offline`, `--no-cache`, `--config=FILE`, `--no-banner`, `--strict`, `-q` / `-v` / `-vv`.

`--strict` (or `"strict": true` in `excubia.json`) exits `3` when a check was skipped because a data source failed (network down and no cache, or an API error), and prints which checks and data sources. Without it, those checks are only marked `skipped` and the exit code ignores them. Deliberate skips never exit `3`: `--offline` without a cached response, `--only` / `--skip`, Laravel checks on a non-Laravel project, a disabled check, or a row removed by an ignore rule. The flag can only turn strict mode on: `--strict` wins over `"strict": false`, and nothing on the command line turns `"strict": true` off.

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | No finding at or above `--fail-on` |
| `1` | At least one finding at or above `--fail-on` |
| `2` | Usage or configuration error (missing/broken `composer.lock`, invalid config, unknown check id) |
| `3` | Only with `--strict`: a check was skipped because a data source failed. Without `--strict` the check is marked `skipped` and the exit code ignores it. |

When several apply, `2` wins over `1`, and `1` wins over `3`, so CI sees real findings first.

In the JSON report every `skipped` finding carries `skipReason`: `data-source-failure`, `offline` (`--offline` found no cached response) or `not-applicable` (the check does not apply or is disabled). The field is optional and additive, so `schemaVersion` stays `1`.

## GitHub Action

The composite action in [`action.yml`](./action.yml) runs Excubia on a Laravel or PHP repository, writes the Markdown report to the job summary and uploads the SARIF log to GitHub code scanning, so findings show up in the **Security** tab.

```yaml
# .github/workflows/excubia.yml
name: Excubia

on:
  push:
    branches: [main]
  pull_request:
  schedule:
    - cron: '0 6 * * 1'

permissions:
  contents: read
  security-events: write

jobs:
  excubia:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: qiick-io/excubia-core@v1
        with:
          fail-on: critical
          github-token: ${{ github.token }}
```

| Input | Default | Notes |
| --- | --- | --- |
| `path` | `.` | Project directory to scan |
| `fail-on` | `critical` | `critical`, `high`, `medium`, `low` or `none`, as in the CLI |
| `only` / `skip` | — | Comma-separated check ids |
| `github-token` | — | Optional; raises the GitHub API rate limit. Passed to Excubia as the `GITHUB_TOKEN` environment variable only |
| `upload-sarif` | `true` | Set to `false` to skip the code scanning upload |
| `php-version` | `8.4` | PHP used to run Excubia, not the scanned project's PHP |

Outputs: `sarif-file` (path of the SARIF log) and `exit-code`.

- Excubia is installed with Composer in `$RUNNER_TEMP/excubia`, from the action's own checkout, so the version always matches the ref you use (`@v1`, a tag or a SHA). The scanned project's code and dependencies are never installed or executed.
- The SARIF log is uploaded even when the step then fails on `fail-on`. Pull requests from forks get a read-only token, so the upload is skipped there.
- Pin the action to a commit SHA if your policy requires it. The `v1` tag is created as a release step.

## Push to excubia-app

`excubia push` runs the same checks as `excubia check` and sends the JSON report to your own self-hosted [`excubia-app`](https://github.com/qiick-io/excubia-app). Nothing is sent until you configure an app URL and a project token.

```bash
export EXCUBIA_APP_URL=https://excubia.example.com   # base URL of your excubia-app
export EXCUBIA_APP_TOKEN=…                            # project push token from the app
vendor/bin/excubia push            # or: php artisan excubia:push
```

The URL and token can also go in `excubia.json` as `"app": {"url": "…", "token": "…"}`; the environment variables win. Keep the token in the environment or your CI secrets rather than a committed file. With `php artisan config:cache`, `.env` is not loaded, so set the variables in the real process environment.

Run it daily from the Laravel scheduler and from CI after each deploy:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('excubia:push')->daily();
```

```yaml
# CI, after deploy
- run: vendor/bin/excubia push
  env:
    EXCUBIA_APP_URL: ${{ vars.EXCUBIA_APP_URL }}
    EXCUBIA_APP_TOKEN: ${{ secrets.EXCUBIA_APP_TOKEN }}
```

Protocol: `POST {url}/api/reports` over HTTPS (plain HTTP only to localhost) with `Authorization: Bearer <token>`, same timeouts and retries as the data sources. The body is an envelope with `protocolVersion` (`1`), a unique `batchId` (a retried send is stored once), `sentAt` and the `report`; see [`docs/push-schema.json`](./docs/push-schema.json) and SPEC §13. Exit codes: `0` sent (findings do not change it), `2` configuration error (missing URL or token, insecure URL), `3` the app rejected the token or could not be reached. `excubia push --strict` (or `"strict": true`) also exits `3` when a data source failure skipped a check, and then sends nothing, so the app never stores an incomplete report.

## Runtime monitoring

In a Laravel app, Excubia can send runtime aggregates (request, slow query, job, scheduled task and outgoing HTTP timings) to your own `excubia-app`. It is **off by default**; while it is off, no listener is registered and nothing runs.

```json
{
  "app": { "url": "https://excubia.example.com" },
  "runtime": {
    "enabled": true,
    "bufferMaxBytes": 1048576,
    "requests": { "enabled": true, "sampleRate": 1 },
    "slowQueries": { "enabled": true, "sampleRate": 1, "thresholdMs": 1000 },
    "jobs": { "enabled": true, "sampleRate": 1 },
    "scheduler": { "enabled": true, "sampleRate": 1 },
    "outgoingHttp": { "enabled": true, "sampleRate": 1 }
  }
}
```

Only `runtime.enabled` is needed; the other keys show the defaults. `sampleRate` is the share of events recorded (0 to 1, as in Laravel Pulse). The app URL and token are the same as for `excubia push` (`EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` win over `app`).

- Events are aggregated in memory per minute (count, errors, total and maximum milliseconds) and written to a local buffer under `storage/excubia/runtime/` when the request or command ends, or after each queued job. Nothing is sent during a request, and an error inside Excubia never reaches your app. Measured overhead: about 0.1 ms per request with everything on.
- When `runtime.enabled` is true the provider schedules `php artisan excubia:push-runtime` every minute; keep the Laravel scheduler running (`schedule:run` from cron or `schedule:work`). It sends the buffer in batches to `POST {url}/api/runtime` (envelope `protocolVersion` `1`, `type` `runtime`, [`docs/runtime-schema.json`](./docs/runtime-schema.json)) and deletes a batch only after a 2xx answer. When the app is down, batches are kept and resent with the same `batchId`; past `bufferMaxBytes` the oldest entries are dropped.
- Exit codes of `excubia:push-runtime`: `0` sent, nothing to send or disabled, `2` configuration error, `3` the app rejected the token, answered an error or could not be reached.

What is sent: route names (`unnamed` for routes without a name), HTTP methods and status classes; slow SQL without bindings, with quoted literals replaced by `?`, and the connection name; job class, queue and connection names; scheduled task descriptions or `artisan <command>`, with paths made relative and secrets masked; the host of outgoing calls. Never URLs or query strings, bodies, headers, cookies, users, IP addresses, query bindings, exception messages, `.env` values or absolute paths.

## Exception capture

In a Laravel app, Excubia can also send the exceptions your app reports to your own `excubia-app`, grouped by fingerprint. It is a **separate opt-in, off by default**, and works with runtime monitoring on or off; while it is off, nothing is registered on the exception handler.

```json
{
  "app": { "url": "https://excubia.example.com" },
  "exceptions": {
    "enabled": true,
    "maskPatterns": ["/ORD-[0-9]+/"],
    "ignore": ["App\\Exceptions\\PaymentDeclinedException"],
    "bufferMaxBytes": 1048576
  }
}
```

Only `exceptions.enabled` is needed. `maskPatterns` are extra regular expressions whose matches become `[masked]`; `ignore` lists exception classes (and subclasses) never captured. Exceptions your app does not report (`dontReport`) are never captured either.

- Excubia adds one `reportable()` callback to Laravel's exception handler. It returns nothing, so your own reporting (log channels, Sentry, Flare, other callbacks) runs exactly as before, and an error inside Excubia never reaches your app.
- Each occurrence is masked, grouped in memory and written to `storage/excubia/exceptions/` when the request, command or job loop ends. The same every-minute `php artisan excubia:push-runtime` sends it to `POST {url}/api/exceptions` (envelope `protocolVersion` `1`, `type` `exceptions`, [`docs/exception-schema.json`](./docs/exception-schema.json)) with the same retries, `batchId` reuse and exit codes as the runtime batches.
- The fingerprint is a sha256 of the class and the in-app stack frames (file relative to the project and `Class::method`, without line numbers), so moving code does not split a group; vendor frames are ignored.

What is sent per group: class, fingerprint, masked message, stack frames (relative file, line, function, in-app flag; never arguments or local variables), the classes of chained exceptions, the context (route name, job class or Artisan command name), the environment, the count and first and last seen times. Messages are masked before they are written to disk: emails, JWTs, bearer tokens, `password=` / `token=` / `api_key=` values, API-key-like strings, UUIDs, card-like and long numbers, IP addresses, URL credentials and query strings, quoted SQL literals, database hosts and absolute paths become placeholders. Never request payloads, headers, cookies, users, IP addresses, `.env` values or absolute paths. The full list is SPEC §7.

## Health endpoint (Oh Dear)

In a Laravel app, Excubia can serve its latest result in the [Oh Dear application health](https://ohdear.app/docs/features/application-health-monitoring) JSON format (also read by monitors built for `spatie/laravel-health`). It is off by default.

```json
{ "healthEndpoint": { "enabled": true, "path": "excubia/health" } }
```

```bash
EXCUBIA_HEALTH_SECRET=a-long-random-string   # required: without it the route is not registered
```

```php
// routes/console.php
Schedule::command('excubia:push')->daily();
Schedule::command('excubia:check --offline --fail-on=none --no-banner')->everyFiveMinutes();
```

`GET /excubia/health` with the `oh-dear-health-check-secret` header answers `finishedAt` and one `checkResults` entry per check (`pass` → `ok`, `warn` → `warning`, `fail` → `failed`, `skipped` → `skipped`; a run that crashed → one `crashed` entry). A wrong or missing secret gets `404`. The endpoint never runs checks in the request: it serves what the last `excubia:check` / `excubia:push` stored in `storage/excubia/health.json`, or one `skipped` entry before the first run. No paths, hostnames or tokens are in the response. Full shape: [`docs/health-endpoint.md`](./docs/health-endpoint.md).

## Health checks

| ID | Name |
| --- | --- |
| `php-eol` | PHP end of life |
| `laravel-eol` | Laravel end of life |
| `node-eol` | Node end of life |
| `composer-advisories` | Known vulnerabilities (Composer) |
| `npm-advisories` | Known vulnerabilities (npm) |
| `composer-abandoned` | Abandoned packages |
| `composer-stale` | Stale packages |
| `laravel-next-blocker` | Next-major blockers |
| `laravel-debug-mode` | Debug mode (Artisan only) |
| `laravel-production-caches` | Production caches (Artisan only) |
| `laravel-pending-migrations` | Pending migrations (Artisan only) |
| `laravel-failed-jobs` | Failed jobs (Artisan only) |
| `laravel-scheduler-heartbeat` | Scheduler heartbeat (Artisan only) |
| `laravel-maintenance-mode` | Maintenance mode (Artisan only) |

The six `laravel-*` posture checks read the booted app, so they run only through `php artisan excubia:check` and `excubia:push`. In `vendor/bin/excubia` they are `skipped` with "requires Artisan". They report only booleans, counts, ages and names, never `.env` values, DSNs, hostnames or paths:

| ID | Warns or fails when |
| --- | --- |
| `laravel-debug-mode` | `app.debug` is on outside `local` / `testing` (fail, high) |
| `laravel-production-caches` | the config, route or event cache is missing in `production`, one row per cache (warn, low); skipped in other environments |
| `laravel-pending-migrations` | migrations have not run (fail, medium) |
| `laravel-failed-jobs` | failed jobs exist, with the count and the age of the oldest (warn, medium; high once the oldest is 24 hours old) |
| `laravel-scheduler-heartbeat` | the scheduler has not run for more than 10 minutes, or never (warn, medium) |
| `laravel-maintenance-mode` | the app is in maintenance mode (warn, medium) |

Scheduler heartbeat: the service provider schedules `php artisan excubia:heartbeat` every minute. It stores the current Unix time under the cache key `excubia:scheduler-heartbeat` in the default cache store, the only thing the posture checks write. Your server only needs the usual `* * * * * php artisan schedule:run` cron (or `schedule:work`). Use a shared cache store (database, Redis) when the scheduler and `excubia:check` run on different machines.

Thresholds live in `excubia.json`: `checks.laravel-scheduler-heartbeat.staleAfterMinutes` (default `10`) and `checks.laravel-failed-jobs.highSeverityAfterHours` (default `24`). Turn a check off with `--skip`, or accept one row with an ignore rule (subjects: `app.debug`, `config-cache`, `route-cache`, `event-cache`, `migrations`, `failed-jobs`, `scheduler`, `maintenance-mode`).

Every report ends with an ordered upgrade plan (`upgrade-plan` section, info only, never affects the exit code, cannot be selected with `--only`/`--skip`).

Full severity rules and data sources: [`docs/SPEC.md`](./docs/SPEC.md) §3.

## Configuration

Optional `excubia.json` at the project root (JSON Schema: [`excubia.schema.json`](./excubia.schema.json)):

```json
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "includeDev": true,
  "strict": false,
  "checks": {
    "composer-stale": { "staleAfterMonths": 24 },
    "php-eol": { "warnDaysBeforeEol": 180 },
    "laravel-eol": { "warnDaysBeforeEol": 180 },
    "node-eol": { "enabled": true },
    "laravel-scheduler-heartbeat": { "staleAfterMinutes": 10 },
    "laravel-failed-jobs": { "highSeverityAfterHours": 24 }
  },
  "ignore": [
    {
      "checkId": "composer-advisories",
      "subject": "vendor/package",
      "advisoryId": "GHSA-xxxx",
      "reason": "Not reachable",
      "until": "2027-01-31"
    }
  ],
  "cache": { "directory": null, "ttlHours": 24 },
  "healthEndpoint": { "enabled": false, "path": "excubia/health" }
}
```

JSON report schema: [`docs/report-schema.json`](./docs/report-schema.json).

### SARIF output

`--format=sarif` writes a [SARIF 2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html) log for GitHub code scanning. Each check id is a rule and each `fail` or `warn` finding is a result; `pass` and `skipped` rows are left out, and so is the upgrade plan. Levels: `critical` and `high` → `error`, `medium` → `warning`, `low` and `info` → `note`; the Excubia severity stays in `properties.severity`. The location is the manifest the finding comes from (`composer.json`, `composer.lock`, `package-lock.json`, `.nvmrc`, `.node-version` or `package.json`; for the `laravel-*` posture checks the Laravel file where the fix starts: `config/app.php`, `config/queue.php`, `database/migrations` or `routes/console.php`), relative to the scanned project, never an absolute path. `partialFingerprints` are built from the check id, subject and advisory id, so an alert keeps its identity across runs. `--fail-on` and the exit codes are the same as for the other formats.

## Privacy

Only package **names and versions** (and runtime versions) leave the machine, and only to the public APIs used by the checks. No file contents, `.env`, source, paths, or hostnames. No telemetry. Optional `GITHUB_TOKEN` is read from the environment only. The only other destination is your own `excubia-app`, and only when you run `excubia push` or turn on [runtime monitoring](#runtime-monitoring) (aggregated route, job, task and host names, durations, counts and SQL without bindings; the list above is exhaustive) or [exception capture](#exception-capture) (class, fingerprint, masked message, relative stack frames without arguments, context and counts; masked before it is written to disk); the project token is never printed, cached or written to a report. Details: [`SECURITY.md`](./SECURITY.md) and SPEC §7.

## Versioning and support

Excubia follows SemVer. Public contracts (report JSON, check ids, exit codes, CLI and Artisan commands, `excubia.json`, push payloads) change only in a major; deprecated options keep working with a warning for at least one minor. In `0.x`, minors may still break and only the latest minor gets fixes. Policy and supported PHP / Laravel versions: [`docs/VERSIONING.md`](./docs/VERSIONING.md).

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Scope is defined by [`docs/SPEC.md`](./docs/SPEC.md); new ideas need a `needs-decision` issue first.

## License

[MIT](./LICENSE)
