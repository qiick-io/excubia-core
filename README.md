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
- **Config** — project-root `excubia.json` with JSON Schema
- **Push** — `excubia push` sends the report to your own self-hosted `excubia-app` (opt-in)

**Non-goals (v0.1.x):** no web scanner yet (planned v0.2.0), no uptime or runtime monitoring yet (Phase B, planned as opt-in inside excubia-core), no auto-fix of the scanned project, no source-code analysis, no telemetry.

## Requirements

| Requirement | Notes |
| --- | --- |
| **PHP ^8.2** | Package floor so it installs as a dev dependency in Laravel 11 / 12 / 13 apps. |
| **Composer 2** | Scans `composer.lock` (required). |
| **Laravel 11–13** (optional) | Needed only for the Artisan alias via package discovery. |

Pest / CI jobs run on PHP 8.3+. Scanned projects may be any Composer app; Laravel-specific checks are skipped when `laravel/framework` is not locked.

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
vendor/bin/excubia cache:clear
vendor/bin/excubia --version
```

Key options: `--format=table|json|markdown|sarif`, `--output=FILE`, `--fail-on=critical|high|medium|low|none` (default `high`), `--only` / `--skip`, `--no-dev`, `--offline`, `--no-cache`, `--config=FILE`, `--no-banner`, `-q` / `-v` / `-vv`.

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | No finding at or above `--fail-on` |
| `1` | At least one finding at or above `--fail-on` |
| `2` | Usage or configuration error (missing/broken `composer.lock`, invalid config, unknown check id) |
| `3` | Reserved for data-source failure; not returned in 0.1.x — an unavailable data source marks the affected check `skipped`. |

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

Protocol: `POST {url}/api/reports` over HTTPS (plain HTTP only to localhost) with `Authorization: Bearer <token>`, same timeouts and retries as the data sources. The body is an envelope with `protocolVersion` (`1`), a unique `batchId` (a retried send is stored once), `sentAt` and the `report`; see [`docs/push-schema.json`](./docs/push-schema.json) and SPEC §13. Exit codes: `0` sent (findings do not change it), `2` configuration error (missing URL or token, insecure URL), `3` the app rejected the token or could not be reached.

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

Every report ends with an ordered upgrade plan (`upgrade-plan` section, info only, never affects the exit code, cannot be selected with `--only`/`--skip`).

Full severity rules and data sources: [`docs/SPEC.md`](./docs/SPEC.md) §3.

## Configuration

Optional `excubia.json` at the project root (JSON Schema: [`excubia.schema.json`](./excubia.schema.json)):

```json
{
  "$schema": "https://raw.githubusercontent.com/qiick-io/excubia-core/main/excubia.schema.json",
  "failOn": "high",
  "includeDev": true,
  "checks": {
    "composer-stale": { "staleAfterMonths": 24 },
    "php-eol": { "warnDaysBeforeEol": 180 },
    "laravel-eol": { "warnDaysBeforeEol": 180 },
    "node-eol": { "enabled": true }
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
  "cache": { "directory": null, "ttlHours": 24 }
}
```

JSON report schema: [`docs/report-schema.json`](./docs/report-schema.json).

### SARIF output

`--format=sarif` writes a [SARIF 2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html) log for GitHub code scanning. Each check id is a rule and each `fail` or `warn` finding is a result; `pass` and `skipped` rows are left out, and so is the upgrade plan. Levels: `critical` and `high` → `error`, `medium` → `warning`, `low` and `info` → `note`; the Excubia severity stays in `properties.severity`. The location is the manifest the finding comes from (`composer.json`, `composer.lock`, `package-lock.json`, `.nvmrc`, `.node-version` or `package.json`), relative to the scanned project, never an absolute path. `partialFingerprints` are built from the check id, subject and advisory id, so an alert keeps its identity across runs. `--fail-on` and the exit codes are the same as for the other formats.

## Privacy

Only package **names and versions** (and runtime versions) leave the machine, and only to the public APIs used by the checks. No file contents, `.env`, source, paths, or hostnames. No telemetry. Optional `GITHUB_TOKEN` is read from the environment only. The only other destination is your own `excubia-app`, and only when you run `excubia push`; the project token is never printed, cached or written to a report. Details: [`SECURITY.md`](./SECURITY.md) and SPEC §7.

## Versioning and support

Excubia follows SemVer. Public contracts (report JSON, check ids, exit codes, CLI and Artisan commands, `excubia.json`, push payloads) change only in a major; deprecated options keep working with a warning for at least one minor. In `0.x`, minors may still break and only the latest minor gets fixes. Policy and supported PHP / Laravel versions: [`docs/VERSIONING.md`](./docs/VERSIONING.md).

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Scope is defined by [`docs/SPEC.md`](./docs/SPEC.md); new ideas need a `needs-decision` issue first.

## License

[MIT](./LICENSE)
