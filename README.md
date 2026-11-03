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

> **Status:** `0.x`, heading for 1.0. The public contracts (CLI options, report JSON, check ids, exit codes, `excubia.json`) freeze at 1.0.0-beta. See [`docs/ROADMAP.md`](./docs/ROADMAP.md) and [`docs/VERSIONING.md`](./docs/VERSIONING.md).

**Excubia** is an open-source (MIT) health check for Laravel apps, part of the [qiick](https://qiick.io) suite. The Composer package [`qiick/excubia-core`](https://packagist.org/packages/qiick/excubia-core) reads your lockfiles and manifests and reports, in one place: PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, blockers for the next Laravel major, and a suggested upgrade order. Run through Artisan, it also checks the posture of the booted app (debug mode, caches, migrations, failed jobs, scheduler, maintenance mode).

Optional, and off by default: push the report to your own self-hosted [`excubia-app`](https://github.com/qiick-io/excubia-app), send runtime aggregates and masked exceptions to it, or serve an Oh Dear-compatible health endpoint. Nothing is sent to qiick or any third party.

| | |
| --- | --- |
| **Guides** | [Configuration](./docs/guides/configuration.md) · [CI](./docs/guides/ci.md) · [excubia-app push](./docs/guides/excubia-app.md) · [Runtime monitoring](./docs/guides/runtime-monitoring.md) · [Exception capture](./docs/guides/exception-capture.md) · [Health endpoint](./docs/guides/health-endpoint.md) · [Privacy](./docs/guides/privacy.md) · [Upgrading to 1.0](./docs/guides/upgrading.md) |
| **Spec** | [`docs/SPEC.md`](./docs/SPEC.md) · [`docs/ARCHITECTURE.md`](./docs/ARCHITECTURE.md) |
| **Changes** | [CHANGELOG.md](./CHANGELOG.md) · [GitHub Releases](https://github.com/qiick-io/excubia-core/releases) |
| **Security** | [`SECURITY.md`](./SECURITY.md) |

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP `^8.2` | To run Excubia. The scanned project may use any PHP version. |
| Composer 2 | `composer.lock` is required in the scanned project. |
| Laravel 12 or 13 (optional) | Only for the Artisan commands and the opt-in features. Laravel 11 is not supported for the Artisan integration (D19); the standalone CLI still scans Laravel 11 projects and reports them as end of life. |

Any Composer project can be scanned. Laravel-specific checks are `skipped` when `laravel/framework` is not locked.

## Quick start

```bash
composer require --dev qiick/excubia-core
vendor/bin/excubia check
```

`vendor/bin/excubia` does not boot your app and never runs project code. In a Laravel app the package is auto-discovered, so the same checks plus the six posture checks run with:

```bash
php artisan excubia:check
```

Install without `--dev` (`composer require qiick/excubia-core`) when the deployed app runs Excubia itself: the scheduled push, runtime monitoring, exception capture, the health endpoint or posture checks in production. While those opt-ins are off, the package registers four Artisan commands and one every-minute scheduled heartbeat, and nothing else.

More examples:

```bash
vendor/bin/excubia check --format=json --output=report.json
vendor/bin/excubia check --format=markdown --output=report.md
vendor/bin/excubia check --fail-on=critical
vendor/bin/excubia check --only=php-eol,composer-advisories
vendor/bin/excubia check --skip=node-eol --no-dev
vendor/bin/excubia check ../other-project --offline
vendor/bin/excubia cache:clear
vendor/bin/excubia --version
```

## Checks

| ID | Name | Data source | Fails or warns when (severity) |
| --- | --- | --- | --- |
| `php-eol` | PHP end of life | endoflife.date | Security support ended (critical); active support ended or security ends within 180 days (medium) |
| `laravel-eol` | Laravel end of life | endoflife.date | Security fixes ended (critical); bug fixes ended or security ends within 180 days (medium) |
| `node-eol` | Node end of life | endoflife.date | EOL (high); maintenance ends within 180 days (low). Skipped when no Node version is declared |
| `composer-advisories` | Known vulnerabilities (Composer) | Packagist security advisories | An advisory affects the locked version (severity from the advisory) |
| `npm-advisories` | Known vulnerabilities (npm) | OSV.dev | A vulnerability affects a version in `package-lock.json` (severity from OSV, default medium) |
| `composer-abandoned` | Abandoned packages | Packagist | Abandoned direct dependency (high); abandoned transitive dependency (medium) |
| `composer-stale` | Stale packages | Packagist, GitHub | Repository archived (high); no release for more than 24 months (low) |
| `laravel-next-blocker` | Next-major blockers | Packagist | The latest release of a direct dependency does not allow the next Laravel major (medium), or only in a pre-release (low) |
| `laravel-debug-mode` | Debug mode | Booted app | `app.debug` on outside `local` / `testing` (high) |
| `laravel-production-caches` | Production caches | Booted app | Config, route or event cache missing in `production` (low, one row per cache) |
| `laravel-pending-migrations` | Pending migrations | Migrator | Migrations have not run (medium) |
| `laravel-failed-jobs` | Failed jobs | Failed job provider | Failed jobs exist (medium; high once the oldest is 24 hours old) |
| `laravel-scheduler-heartbeat` | Scheduler heartbeat | Default cache store | The scheduler has not run for more than 10 minutes, or never (medium) |
| `laravel-maintenance-mode` | Maintenance mode | Booted app | The app is in maintenance mode (medium) |

- The six `laravel-*` posture checks after `laravel-next-blocker` need the booted app, so only `php artisan excubia:check` and `excubia:push` run them. `vendor/bin/excubia` reports them as `skipped` ("requires Artisan").
- For `laravel-scheduler-heartbeat`, the service provider schedules `php artisan excubia:heartbeat` every minute. It stores the Unix time under the cache key `excubia:scheduler-heartbeat` in the default cache store. Keep the usual `* * * * * php artisan schedule:run` cron (or `schedule:work`), and use a shared cache store (database, Redis) when the scheduler and `excubia:check` run on different machines.
- Every report ends with an ordered upgrade plan (`upgrade-plan`): security fixes, runtime EOL, blockers, Laravel major, replacements. It is `info` only, never changes the exit code and cannot be selected with `--only` / `--skip`.
- Thresholds (180 days, 24 months, 10 minutes, 24 hours) are configurable in [`excubia.json`](./docs/guides/configuration.md#checks). Full severity rules: [`docs/SPEC.md`](./docs/SPEC.md) section 3.

## Formats

| `--format` | Output |
| --- | --- |
| `table` (default) | Human-readable, grouped by severity, coloured on a TTY (not with `NO_COLOR` or when writing to a file), summary line and upgrade plan |
| `json` | Versioned report, `schemaVersion` `1`, schema in [`docs/report-schema.json`](./docs/report-schema.json). Parse this, not the text formats |
| `markdown` | For pull requests, job summaries and AI agents |
| `sarif` | SARIF 2.1.0 for GitHub code scanning, see [CI guide](./docs/guides/ci.md#sarif) |

In the JSON report every `skipped` finding has a `skipReason`: `data-source-failure`, `offline` (`--offline` found no cached response) or `not-applicable` (the check does not apply or is disabled).

The JSON report also carries, as optional fields of schema version 1:

- top level: `generatedAt` (UTC, `Y-m-d\TH:i:s\Z`), `excubiaVersion` (the value of `--version`) and `project` with `php`, `laravel` and `node`, each `{version, cycle, securitySupportEndsOn, activeSupportEndsOn}` or `null` when not declared (no name, path or hostname). The cycle and the `Y-m-d` dates come from the endoflife.date data the EOL checks already read, also when they pass; they are `null` when that check was skipped or did not run;
- per finding: `fingerprint` (sha256 of `checkId`, `subject` and `advisoryId` joined by a NUL byte, the same value as the SARIF `partialFingerprints`, stable across runs), `advisoryId` on advisory findings, and `details` with check-specific values so you do not parse `message`:

| `checkId` | `details` keys |
| --- | --- |
| `composer-advisories` | `package`, `lockedVersion`, `affectedVersions` (Composer constraint), `fixedVersions` (list: the exclusive upper bound of each affected range) |
| `npm-advisories` | `package`, `lockedVersion`, `fixedVersions` (always empty for now: the OSV batch answer has ids only) |
| `php-eol`, `laravel-eol`, `node-eol` | `cycle`, `securitySupportEndsOn`, `activeSupportEndsOn` (`Y-m-d` or `null`) |
| `composer-abandoned` | `package`, `lockedVersion`, `replacement` (or `null`) |
| `composer-stale` | `package`, `lockedVersion` |
| `laravel-next-blocker` | `package`, `lockedVersion`, `targetMajor` (integer) |
| `laravel-pending-migrations` | `count` |
| `laravel-failed-jobs` | `count`, `oldestAgeHours` (or `null`) |
| `laravel-scheduler-heartbeat` | `minutesSinceLastRun` (`null`: never ran) |
| private packages row of the five checks that send names | `count` |

`laravel-debug-mode`, `laravel-production-caches`, `laravel-maintenance-mode` and other skipped rows have no `details`. The exact rules are in [`docs/report-schema.json`](./docs/report-schema.json).

## CLI

```
vendor/bin/excubia check [path] [options]
vendor/bin/excubia push [path] [--config=FILE] [--strict]
vendor/bin/excubia cache:clear [path] [--config=FILE]
```

`path` is the project directory to scan (default: the current directory).

| Option | Commands | Meaning |
| --- | --- | --- |
| `--format=FORMAT` | `check` | `table` (default), `json`, `markdown` or `sarif` |
| `--output=FILE` | `check` | Write the report to `FILE` instead of stdout. The only file Excubia writes in your project |
| `--fail-on=SEVERITY` | `check` | `critical`, `high` (default), `medium`, `low` or `none`. A finding at or above it makes the exit code `1`. Wins over `failOn` in `excubia.json` |
| `--only=IDS` | `check` | Comma-separated check ids to run. Unknown ids are an error |
| `--skip=IDS` | `check` | Comma-separated check ids to skip. With `--only`, applied to that selection |
| `--no-dev` | `check` | Ignore `require-dev` / `packages-dev`. Same as `"includeDev": false` |
| `--offline` | `check` | Use cached data-source responses only; checks without a cached response are `skipped` |
| `--no-cache` | `check` | Do not read or write the response cache |
| `--config=FILE` | all | Config file (default: `excubia.json` in the project, if present). A missing explicit file is an error |
| `--no-banner` | `check` | Hide the ASCII banner |
| `--strict` | `check`, `push` | Exit `3` when a data-source failure skipped a check; `push` then sends nothing. Same as `"strict": true` |
| `-q`, `-v`, `-vv` | all | Quiet, verbose, debug. From `-v` every HTTP request is listed on stderr |
| `--version` | | Print the Excubia version |

The Artisan commands take the same options, without `path` (they always scan the app's base path):

| Artisan command | Options | What it does |
| --- | --- | --- |
| `php artisan excubia:check` | as `check` | Runs every check, posture checks included |
| `php artisan excubia:push` | `--config`, `--strict` | Runs the checks and sends the report to your `excubia-app` ([guide](./docs/guides/excubia-app.md)) |
| `php artisan excubia:push-runtime` | `--config` | Sends the buffered runtime aggregates and exceptions ([guide](./docs/guides/runtime-monitoring.md)). Scheduled every minute when an opt-in is on |
| `php artisan excubia:heartbeat` | | Stores the scheduler heartbeat. Scheduled every minute by the provider |

### Exit codes

| Code | `check` | `push`, `push-runtime` |
| --- | --- | --- |
| `0` | No finding at or above `--fail-on` | Sent, or nothing to send. Findings do not change it |
| `1` | At least one finding at or above `--fail-on` | — |
| `2` | Usage or configuration error: missing or broken `composer.lock`, invalid `excubia.json`, unknown check id, bad option value | Configuration error: missing URL or token, plain HTTP to a host other than localhost, invalid config |
| `3` | Only in strict mode: a check was skipped because a data source failed | The app rejected the token, answered an error or could not be reached; in strict mode also a data-source failure (nothing is sent) |

When several apply, `2` wins over `1`, and `1` wins over `3`, so CI sees real findings first.

Strict mode is `--strict` or `"strict": true`. A failed data source means the network was down with no cached response, or an API answered an error; the message on stderr names each check and data source. Without strict mode such checks are only marked `skipped` and the exit code ignores them. Deliberate skips never exit `3`: `--offline` without a cached response, `--only` / `--skip`, Laravel checks on a non-Laravel project, a disabled check, or a row removed by an ignore rule. `--strict` can only turn strict mode on: it wins over `"strict": false`, and nothing on the command line turns `"strict": true` off.

## Configuration

Excubia works without configuration. To change defaults, add `excubia.json` at the project root:

```json
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "checks": {
    "composer-stale": { "staleAfterMonths": 24 }
  },
  "ignore": [
    {
      "checkId": "composer-advisories",
      "subject": "vendor/package",
      "advisoryId": "GHSA-xxxx-xxxx-xxxx",
      "reason": "Not reachable from our code",
      "until": "2027-01-31"
    }
  ]
}
```

Unknown keys are a configuration error (exit `2`). Every key, type and default, and the environment variables (`EXCUBIA_APP_URL`, `EXCUBIA_APP_TOKEN`, `EXCUBIA_HEALTH_SECRET`, `GITHUB_TOKEN`), are in the [configuration reference](./docs/guides/configuration.md). Put the push token in `EXCUBIA_APP_TOKEN`, not in `excubia.json`, which is normally committed; Excubia warns when `app.token` is set in the file. JSON Schema: [`excubia.schema.json`](./excubia.schema.json).

## Guides

- [Configuration reference](./docs/guides/configuration.md): every `excubia.json` key and environment variable.
- [CI](./docs/guides/ci.md): the GitHub Action with code scanning, and any other CI.
- [Push to excubia-app](./docs/guides/excubia-app.md): from the scheduler and after a deploy.
- [Runtime monitoring](./docs/guides/runtime-monitoring.md): request, query, job, task and outgoing HTTP aggregates.
- [Exception capture](./docs/guides/exception-capture.md): masked exceptions grouped by fingerprint.
- [Health endpoint](./docs/guides/health-endpoint.md): Oh Dear application health JSON.
- [Privacy](./docs/guides/privacy.md): exactly what leaves the machine, per feature.
- [Upgrading from 0.x to 1.0](./docs/guides/upgrading.md).

## Privacy

By default only package names and versions (and runtime versions) leave the machine, and only to the public data sources in the check table. No file contents, `.env` values, source code, paths or hostnames. Private packages (path, VCS, Satis, Private Packagist, `file:` or other-registry origins in the lockfile, plus `privacy.skipPackages`) are never sent and show as `skipped`. No telemetry. Each opt-in that sends data to your own `excubia-app` is listed field by field in the [privacy guide](./docs/guides/privacy.md).

## Versioning and support

Excubia follows SemVer. Public contracts change only in a major; deprecated options keep working with a warning for at least one minor. In `0.x`, minors may still break and only the latest minor gets fixes. Policy and supported versions: [`docs/VERSIONING.md`](./docs/VERSIONING.md).

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Scope is defined by [`docs/SPEC.md`](./docs/SPEC.md); new ideas need a `needs-decision` issue first.

## License

[MIT](./LICENSE)
