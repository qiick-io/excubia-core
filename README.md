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
- **Formats** — `table` (default), `json`, `markdown`
- **CI-friendly** — `--fail-on`, exit codes, optional `--offline` cache
- **Config** — project-root `excubia.json` with JSON Schema

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
vendor/bin/excubia check --fail-on=critical
vendor/bin/excubia check --only=php-eol,composer-advisories
vendor/bin/excubia check --skip=node-eol --no-dev
vendor/bin/excubia check --offline
vendor/bin/excubia cache:clear
vendor/bin/excubia --version
```

Key options: `--format=table|json|markdown`, `--output=FILE`, `--fail-on=critical|high|medium|low|none` (default `high`), `--only` / `--skip`, `--no-dev`, `--offline`, `--no-cache`, `--config=FILE`, `--no-banner`, `-q` / `-v` / `-vv`.

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | No finding at or above `--fail-on` |
| `1` | At least one finding at or above `--fail-on` |
| `2` | Usage or configuration error (missing/broken `composer.lock`, invalid config, unknown check id) |
| `3` | Reserved for data-source failure; not returned in 0.1.x — an unavailable data source marks the affected check `skipped`. |

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

## Privacy

Only package **names and versions** (and runtime versions) leave the machine, and only to the public APIs used by the checks. No file contents, `.env`, source, paths, or hostnames. No telemetry. Optional `GITHUB_TOKEN` is read from the environment only. Details: [`SECURITY.md`](./SECURITY.md) and SPEC §7.

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Scope is defined by [`docs/SPEC.md`](./docs/SPEC.md); new ideas need a `needs-decision` issue first.

## License

[MIT](./LICENSE)
