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

> **Status:** 1.0.0-beta. The public contracts (CLI options, report JSON, check ids, exit codes, `excubia.json`, push payloads, health endpoint, SARIF ids, Action inputs) are frozen for 1.0.0-beta: [`docs/CONTRACTS.md`](./docs/CONTRACTS.md). See [`docs/ROADMAP.md`](./docs/ROADMAP.md) and [`docs/VERSIONING.md`](./docs/VERSIONING.md).

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
| `npm-advisories` | Known vulnerabilities (npm) | OSV.dev | A vulnerability affects a version in the JavaScript lockfile (severity from OSV, default medium) |
| `composer-abandoned` | Abandoned packages | Packagist | Abandoned direct dependency (high); abandoned transitive dependency (medium) |
| `composer-stale` | Stale packages | Packagist, GitHub | Repository archived (high); no release for more than 24 months (low) |
| `laravel-next-blocker` | Next-major blockers | Packagist | The latest release of a direct dependency does not allow the next Laravel major (medium), or only in a pre-release (low) |
| `laravel-debug-mode` | Debug mode | Booted app | `app.debug` on outside `local` / `testing` (high) |
| `laravel-production-caches` | Production caches | Booted app | Config, route or event cache missing in `production` (low, one row per cache) |
| `laravel-pending-migrations` | Pending migrations | Migrator | Migrations have not run (medium) |
| `laravel-failed-jobs` | Failed jobs | Failed job provider | Failed jobs exist (medium; high once the oldest is 24 hours old) |
| `laravel-scheduler-heartbeat` | Scheduler heartbeat | Default cache store | The scheduler has not run for more than 10 minutes, or never (medium) |
| `laravel-maintenance-mode` | Maintenance mode | Booted app | The app is in maintenance mode (medium) |
| `laravel-app-key` | Application key | Booted app | `app.key` is empty, in any environment (critical) |
| `laravel-session-security` | Session cookie security | Booted app | In `production`: `session.secure` false while `app.url` is https, or `session.http_only` false (medium, one row per key) |
| `laravel-cors-wildcard` | CORS wildcard origin | Booted app | In `production`: `cors.allowed_origins` contains `*` with `cors.supports_credentials` (high). `*` alone passes |
| `laravel-production-drivers` | Production drivers | Booted app | In `production`: queue `sync`, cache `array` / `null`, session `array`, mail `log` / `array` (medium, one row per driver) |
| `laravel-log-level` | Log level | Booted app | In `production`: the default log channel logs at `debug` (low) |
| `laravel-dev-packages` | Development packages | `composer.json`, booted app | In `production`: `laravel/telescope`, `barryvdh/laravel-debugbar`, `spatie/laravel-ray`, `laravel/pail` or `itsgoingd/clockwork` is in `require` (not `require-dev`) and enabled (medium, one row per package) |
| `dependency-licenses` | Dependency licenses | `composer.lock`, `package-lock.json` (no request; `yarn.lock` and `pnpm-lock.yaml` record no licenses) | A license in `checks.dependency-licenses.denied` (fail, high); a strong copyleft license such as GPL, AGPL, SSPL or EUPL (medium); a missing or unknown license (low). Development dependencies only with `includeDev` |
| `laravel-database` | Database | Database connections | The default connection, or one listed in `checks.laravel-database.connections`, does not answer `select 1` (critical, one row per connection) |
| `laravel-cache` | Cache | Default cache store | The default store cannot write, read and delete a probe key (high). Skipped with the `null` driver |
| `laravel-storage` | Writable storage | Booted app | `storage` or `bootstrap/cache` is not writable (high, one row per directory) |
| `laravel-disk-space` | Disk space | Disk holding `storage/` | At least 80 % used (medium), at least 90 % (high, fail) |
| `laravel-queue-backlog` | Queue backlog | Default queue connection | A queue's oldest pending job has waited more than 15 minutes, or it holds more than 1000 pending jobs (medium, one row per queue). `database` and `redis` drivers only, others skipped |
| `php-platform-requirements` | Platform requirements | `composer.lock`, running PHP | The running PHP misses an extension, or its version does not satisfy a `php` constraint, that a locked package or the root `platform` requires (high, one row per requirement), like `composer check-platform-reqs` |
| `env-example-sync` | `.env.example` sync | `.env.example`, `config/*.php` (key names only, no request) | A key that `config/*.php` reads with `env()` is missing from `.env.example` (low, one row per key); keys in `.env.example` that no config file reads are one `info` row. Skipped without `.env.example` or `config/` |
| `composer-outdated` | Outdated packages | Packagist (the metadata `composer-abandoned` and `composer-stale` already read) | A direct dependency has a newer stable release: behind on a patch (info), on a minor (low) or on a major (low), one row per package, like `composer outdated --direct` |
| `laravel-queue-workers` | Queue workers | Laravel Horizon status, or the worker heartbeat in the default cache store | With Horizon: `inactive` (high, fail), `paused` (medium). Without Horizon: a queue of the default connection has pending jobs and no worker has reported on it for more than 5 minutes (medium, one row per queue). Skipped with the `sync` driver, drivers other than `database` / `redis`, and while no worker has ever reported |
| `npm-outdated` | Outdated packages (npm) | npm registry (`registry.npmjs.org`, package names only) | A direct dependency in `package.json` has a newer stable release: behind on a patch (info), on a minor (low) or on a major (low), one row per package, like `npm outdated`. Skipped without a JavaScript lockfile |
| `laravel-backups` | Backups | spatie/laravel-backup destinations (when installed) | A monitored destination has no backup, cannot be reached, or its newest backup is older than 26 hours (high, fail); the newest backup is less than half the size of the previous one (medium). One row per destination. Skipped without spatie/laravel-backup |
| `laravel-database-connections` | Database connections | Default database connection (`Threads_connected` on MySQL / MariaDB, `pg_stat_activity` on PostgreSQL) | More than 50 open connections (medium), more than 100 (high, fail); other drivers skipped |
| `laravel-database-size` | Database size | Default database (`information_schema` on MySQL / MariaDB, `pg_database_size` on PostgreSQL, the file size on SQLite) | Reports the size and the five largest tables (pass, info); above `checks.laravel-database-size.warnAtMb` (unset by default) it warns (medium) |
| `laravel-redis-memory` | Redis memory | `INFO memory` of the Redis connections the default cache store, queue connection and session use | 80 % of `maxmemory` used or more (medium), 90 % or more (high, fail); no `maxmemory` limit, or the `noeviction` policy on the cache connection (low). One row per connection. Skipped when Redis is not used or does not answer |
| `php-opcache` | OPcache | The web-SAPI snapshot the health endpoint stores (never the CLI) | In production: OPcache disabled (medium), `opcache.validate_timestamps` on (low). In any environment: hit rate under 90 % or more than 90 % of the OPcache memory used (low). Skipped without a web snapshot |
| `php-production-ini` | Production php.ini | Same as `php-opcache` | In production only: `display_errors` on (high, fail), `expose_php` on (low), `log_errors` off (low). One row per directive, booleans only. Skipped outside production and without a web snapshot |
| `laravel-octane-config` | Octane configuration | `octane.server` and `octane.max_requests`, when Laravel Octane is installed | Always one info row with the server type. In production only: `max_requests` unset (low) or 0 (medium, workers never restart, so leaks build up). Skipped without `laravel/octane` |
| `laravel-reverb` | Reverb server | A TCP connect (2 s timeout) to the host and port of the Reverb broadcast connection, when Laravel Reverb is installed and is the default broadcast driver | The server does not answer (high, fail: broadcasts fail). One info row with the connect time otherwise; never the host or port. Skipped without `laravel/reverb`, when Reverb is not the broadcast driver or names no host |
| `laravel-cashier` | Cashier billing | Per installed Cashier package (Stripe or Paddle): whether the webhook secret is set, whether the keys are test keys, whether a route reaches Cashier's webhook controller. Booleans only | No webhook secret (high in production, low elsewhere: forged webhooks are accepted), test keys in production (high, fail), no webhook route (medium). One info row otherwise. Skipped without `laravel/cashier` and `laravel/cashier-paddle` |
| `laravel-api-tokens` | API tokens | Per installed package, Laravel Sanctum: `sanctum.expiration`, the `stateful` domains (wildcard or none) and counts of its valid tokens, stale (unused 90 days) and never used; Laravel Passport: keys present, private key file mode, token lifetimes and the number of personal access clients. Counts and booleans only | Sanctum tokens never expire (low, medium in production), stale tokens (low), in production a `stateful` wildcard (medium) or no domain (low); Passport without keys (high in production, fail), a private key readable by others (high in production, fail), very long token lifetimes (low). One info row otherwise. Skipped without `laravel/sanctum` and `laravel/passport` |

- Supported lockfiles: `composer.lock` (Composer 2, required) and one JavaScript lockfile: `package-lock.json` (lockfileVersion 2 and 3), `yarn.lock` (Yarn classic v1 and Yarn Berry v2+) or `pnpm-lock.yaml` (lockfileVersion 6 and 9). When several exist, Excubia reads the one of the `packageManager` in `package.json`, else the first of `pnpm-lock.yaml`, `yarn.lock`, `package-lock.json`; the JSON report names it in `javascriptLockfile`, npm findings in their message and SARIF location.
- `dependency-licenses` reads the licenses the lockfiles already record and sends nothing. `yarn.lock` and `pnpm-lock.yaml` record none: their packages get one `skipped` row ("N packages from yarn.lock have no license data") instead of a low warning each. An SPDX expression such as `MIT OR GPL-3.0-only` passes when any option is fine; `AND` needs every part to be. Allow or deny licenses in [`excubia.json`](./docs/guides/configuration.md#dependency-licenses).
- `env-example-sync` compares key names only: it tokenizes `config/*.php` (never runs it), reads the names in `.env.example` and drops the values, and never opens `.env`. Optional Laravel keys (`AWS_*`, `PUSHER_*`, `VITE_*`, `MEMCACHED_*`, `REDIS_CLIENT`, …) are ignored by default; extend or replace the list with [`checks.env-example-sync.ignoreKeys`](./docs/guides/configuration.md#env-example-sync).
- `composer-outdated` compares each direct dependency in `composer.json` (`require`, plus `require-dev` unless `--no-dev`) with the newest stable release on Packagist, using Composer's own version rules. Pre-releases count only when the locked version is itself a pre-release; `minimum-stability` is not read. Locked dev branches are left out. It reuses the Packagist metadata the other Composer checks fetch, so it adds no request.
- `npm-outdated` does the same for `package.json` (`dependencies` and `optionalDependencies`, plus `devDependencies` unless `--no-dev`), with the versions locked in the JavaScript lockfile Excubia reads. It sends one request per public direct dependency to `https://registry.npmjs.org/<name>`, carrying the name only (abbreviated metadata); private packages are never sent. Pre-releases count only when the locked version is itself one, and a release newer than the `latest` dist-tag is ignored. Versions are compared with Composer's version rules, so an unusual pre-release tag (`1.0.0-canary.abc`) is left out. `--offline` reads cached answers only.
- The seventeen `laravel-*` checks after `laravel-next-blocker`, `laravel-queue-workers`, `laravel-backups`, `laravel-database-connections`, `laravel-database-size`, `laravel-redis-memory`, `php-opcache`, `php-production-ini`, `laravel-octane-config`, `laravel-reverb`, `laravel-cashier` and `laravel-api-tokens` need the booted app, so only `php artisan excubia:check` and `excubia:push` run them. `vendor/bin/excubia` reports them as `skipped` ("requires Artisan").
- `php-platform-requirements` also runs in `vendor/bin/excubia`, but that is the PHP of the CLI, which can differ from the app's: its findings say so and carry `isStandaloneCli: true`. Under Artisan it checks the app's PHP. Extensions that a locked package provides (polyfills) count as present; `config.platform` overrides do not.
- The infrastructure checks (`laravel-database`, `laravel-cache`, `laravel-storage`, `laravel-disk-space`, `laravel-queue-backlog`) only read, except the cache probe key `excubia:cache-probe:<random>`, which is deleted right away. A service that does not answer is a finding, never an exception in the app. Findings name the connection, store, directory or queue, and carry percentages, counts and ages: never hostnames, DSNs, credentials or absolute paths.
- `laravel-database-connections` and `laravel-database-size` run read-only catalog queries on the default connection only (`show status`, `pg_stat_activity`, `information_schema.tables`, `pg_database_size`, SQLite `dbstat` when the build has it), opened with the same 3 s connect timeout as `laravel-database`; a query that fails skips the check. They report the connection name, counts, megabytes and up to five table names (cut to 64 characters), never the database name, host or SQLite file path.
- `php-opcache` and `php-production-ini` need the [health endpoint](./docs/health-endpoint.md). Artisan runs on the CLI SAPI, whose OPcache follows `opcache.enable_cli` (off by default) and which often has its own `php.ini`, so its values say nothing about PHP-FPM and are never checked. Instead, each authenticated health endpoint request (at most once an hour) stores a web-SAPI snapshot of the same values, booleans, percentages and a count only, under the cache key `excubia:php-runtime:web` in the default cache store for a day, and both checks read it (`sapi: "web"`, `ageMinutes` its age). Without a web snapshot both are `skipped` (`not-applicable`): "PHP web settings unknown: enable the health endpoint so Excubia can read the web SAPI (the CLI uses different ini settings)." Neither check ever reports an ini file path or value.
- For `laravel-scheduler-heartbeat`, the service provider schedules `php artisan excubia:heartbeat` every minute. It stores the Unix time under the cache key `excubia:scheduler-heartbeat` in the default cache store. Keep the usual `* * * * * php artisan schedule:run` cron (or `schedule:work`), and use a shared cache store (database, Redis) when the scheduler and `excubia:check` run on different machines.
- For `laravel-queue-workers` without Horizon, the service provider listens to the queue worker's `Looping` event and, at most once a minute per worker queue, stores the Unix time under the cache key `excubia:worker-heartbeat:<connection>:<queue>` in the default cache store. Restart your workers (`php artisan queue:restart`) after installing Excubia, and use a shared cache store when workers and `excubia:check` run on different machines. Horizon is not a dependency: when it is installed the check reads its status through Horizon's own repositories. Findings carry connection, queue and supervisor names (without the hostname prefix), never hostnames.
- spatie/laravel-backup is not a dependency: when it is installed, `laravel-backups` lists the backups of each destination in `backup.monitor_backups` (or `backup.backup.destination.disks` when nothing is monitored) through its `BackupDestination` API, and only sizes the two newest. Findings carry the disk name, the age in hours, the size in MB and the count: never a disk root, bucket, path or credential.
- `laravel-redis-memory` sends `INFO memory` on each Redis connection the default cache store, the default queue connection or the session use (or on `checks.laravel-redis-memory.connections` instead) and keeps only `used_memory`, `maxmemory` and `maxmemory_policy`. A connection that does not answer is left out; `laravel-cache` reports reachability. Findings carry the connection name, megabytes and the policy: never a host, port or password.
- Every report ends with an ordered upgrade plan (`upgrade-plan`): security fixes, runtime EOL, blockers, Laravel major, replacements, then one step with the latest patch of every outdated Composer package that has no advisory, the same for npm packages, and a license review step when `dependency-licenses` finds a denied license (naming the denied and strong copyleft packages). It is `info` only, never changes the exit code and cannot be selected with `--only` / `--skip`.
- Thresholds (180 days, 24 months, 10 minutes, 24 hours, 80 / 90 %, 15 minutes, 1000 jobs, 5 minutes, 26 hours, 80 / 90 % of `maxmemory`) are configurable in [`excubia.json`](./docs/guides/configuration.md#checks). Full severity rules: [`docs/SPEC.md`](./docs/SPEC.md) section 3.

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
- top level, when the project has a JavaScript lockfile: `javascriptLockfile` (`package-lock.json`, `yarn.lock` or `pnpm-lock.yaml`), the one the npm checks read;
- top level, from `php artisan excubia:check` and `excubia:push` only (#146): `schedule`, the scheduled task inventory of the booted app, one `{taskId, task, expression, timezone, withoutOverlapping, onOneServer, runsInMaintenanceMode, environments}` per event plus `lateAfterMinutes` (the configured grace), named like the runtime `scheduledTask` aggregates (description, `artisan <command name>`, executable file name or `Closure`, never arguments); `taskId` is sha256 of `task`, a NUL byte and `expression`, the same value the runtime aggregates carry. The standalone CLI cannot boot the app and leaves it out;
- top level, when `dependency-licenses` ran: `licenses`, the license inventory without package names: `packageCount`, `byLicense` (list of `{license, packageCount}`, `NOASSERTION` for packages that declare none) and `byEcosystem` with the same counts for `composer` and `npm`;
- per finding: `fingerprint` (sha256 of `checkId`, `subject` and `advisoryId` joined by a NUL byte, the same value as the SARIF `partialFingerprints`, stable across runs), `advisoryId` on advisory findings, and `details` with check-specific values so you do not parse `message`:

| `checkId` | `details` keys |
| --- | --- |
| `composer-advisories` | `package`, `lockedVersion`, `affectedVersions` (Composer constraint), `fixedVersions` (list: the exclusive upper bound of each affected range) |
| `npm-advisories` | `package`, `lockedVersion`, `fixedVersions` (always empty for now: the OSV batch answer has ids only), `lockfile` (the JavaScript lockfile) |
| `php-eol`, `laravel-eol`, `node-eol` | `cycle`, `securitySupportEndsOn`, `activeSupportEndsOn` (`Y-m-d` or `null`) |
| `composer-abandoned` | `package`, `lockedVersion`, `replacement` (or `null`) |
| `composer-stale` | `package`, `lockedVersion` |
| `laravel-next-blocker` | `package`, `lockedVersion`, `targetMajor` (integer) |
| `laravel-pending-migrations` | `count` |
| `laravel-failed-jobs` | `count`, `oldestAgeHours` (or `null`) |
| `laravel-scheduler-heartbeat` | `minutesSinceLastRun` (`null`: never ran) |
| `laravel-cors-wildcard` | `hasCredentials` |
| `laravel-production-drivers` | `component` (`queue`, `cache`, `session`, `mail`), `driver` |
| `laravel-dev-packages` | `package` |
| `dependency-licenses` | `package`, `lockedVersion`, `licenses` (list of SPDX ids or expressions as declared, empty when none), `ecosystem` (`composer` or `npm`), `kind` (`denied`, `copyleft` or `unknown`, #204) |
| `laravel-database` | `connection` |
| `laravel-cache` | `store` |
| `laravel-storage` | `directory` (`storage` or `bootstrap/cache`) |
| `laravel-disk-space` | `usedPercent` |
| `laravel-queue-backlog` | `queue`, `pendingCount`, `oldestAgeMinutes` (or `null`) |
| `php-platform-requirements` | `requirement` (`php` or `ext-*`), `requiredBy` (list of packages, `composer.json` for the root), `checkedPhpVersion`, `isStandaloneCli` |
| `env-example-sync` | missing key rows: `key`, `configFiles` (project-relative, such as `config/services.php`); the `unused keys` row: `keys` (names only, never values) |
| `composer-outdated` | `package`, `lockedVersion`, `latestPatch` (newest release in the locked minor), `latestMinor` (newest in the locked major), `latestMajor` (newest in a later major), each `null` when nothing newer exists there, and `isMajorBehind` |
| `laravel-queue-workers` | without Horizon: `connection`, `queue`, `minutesSinceLastSeen` (`null`: no worker ever reported on the queue); with Horizon: `status` (`inactive` or `paused`), `processes` (worker process count), `supervisors` (names, without the hostname prefix) |
| `npm-outdated` | the same keys as `composer-outdated`, versions as the npm registry prints them |
| `laravel-backups` | `disk` (filesystem disk name), `ageHours` and `sizeMb` of the newest backup (`null` when there is none or the destination cannot be reached), `count` (`null` when unreachable) |
| `laravel-database-connections` | `connection`, `connectionCount` |
| `laravel-database-size` | `connection`, `sizeMb` (two decimals), `largestTables` (up to five `{table, sizeMb}`, largest first; empty when the catalog cannot be read) |
| `laravel-redis-memory` | `connection` (Redis connection name), `usedMb`, `maxMb` (`null` when `maxmemory` is 0), `policy` (`maxmemory_policy`, `null` when not reported) |
| `php-opcache` | `sapi` (always `web`), `ageMinutes` (age of the web snapshot), `isOpcacheEnabled`, `hitRate` and `memoryUsedPercent` (percent, `null` when OPcache is off), `cachedScripts`, `isValidateTimestampsEnabled` |
| `php-production-ini` | `directive` (`display_errors`, `expose_php` or `log_errors`), `isEnabled`, `sapi`, `ageMinutes` |
| `laravel-octane-config` | `server` (`swoole`, `roadrunner`, `frankenphp` or `unknown`), `maxRequests` (`null` when unset) |
| `laravel-reverb` | `isReachable`, `latencyMs` (`null` when the connect failed) |
| `laravel-cashier` | `provider` (`stripe` or `paddle`), `isWebhookSecretConfigured`, `isWebhookRouteRegistered`, `hasTestKeys` |
| `laravel-api-tokens` | Sanctum: `provider` (`sanctum`), `expirationMinutes`, `hasStatefulWildcard`, `isStatefulEmpty`, `tokenCount`, `nonExpiringTokenCount`, `staleTokenCount`, `neverUsedTokenCount`. Passport: `provider` (`passport`), `hasKeys`, `isPrivateKeyTooOpen`, `accessTokenLifetimeMinutes`, `refreshTokenLifetimeMinutes`, `personalAccessTokenLifetimeMinutes`, `personalAccessClientCount` |
| private packages row of the seven checks that send names | `count` |

`laravel-debug-mode`, `laravel-production-caches`, `laravel-maintenance-mode`, `laravel-app-key`, `laravel-session-security`, `laravel-log-level` and other skipped rows have no `details`. The exact rules are in [`docs/report-schema.json`](./docs/report-schema.json).

## CLI

```
vendor/bin/excubia check [path] [options]
vendor/bin/excubia push [path] [--config=FILE] [--strict] [--deploy]
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
| `--no-banner` | `check` | Hide the ASCII banner. `json` and `sarif` never print it |
| `--strict` | `check`, `push` | Exit `3` when a data-source failure skipped a check; `push` then sends nothing. Same as `"strict": true` |
| `--deploy` | `push` | Mark the report as a deploy: it carries `deployedAt` next to its `release`, so `excubia-app` draws a deploy marker. Run it as the post-deploy step ([CI guide](./docs/guides/ci.md#deploy-markers)) |
| `-q`, `-v`, `-vv` | all | Quiet, verbose, debug. From `-v` every HTTP request is listed on stderr |
| `--version` | | Print the Excubia version |

The Artisan commands take the same options, without `path` (they always scan the app's base path):

| Artisan command | Options | What it does |
| --- | --- | --- |
| `php artisan excubia:check` | as `check` | Runs every check, posture checks included |
| `php artisan excubia:push` | `--config`, `--strict`, `--deploy` | Runs the checks and sends the report to your `excubia-app` ([guide](./docs/guides/excubia-app.md)) |
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

Unknown keys are a configuration error (exit `2`). Every key, type and default, and the environment variables (`EXCUBIA_APP_URL`, `EXCUBIA_APP_TOKEN`, `EXCUBIA_RELEASE`, `EXCUBIA_HEALTH_SECRET`, `EXCUBIA_SERVER_NAME`, `GITHUB_TOKEN`), are in the [configuration reference](./docs/guides/configuration.md). Put the push token in `EXCUBIA_APP_TOKEN`, not in `excubia.json`, which is normally committed; Excubia warns when `app.token` is set in the file. JSON Schema: [`excubia.schema.json`](./excubia.schema.json).

## Guides

- [Configuration reference](./docs/guides/configuration.md): every `excubia.json` key and environment variable.
- [CI](./docs/guides/ci.md): the GitHub Action with code scanning, and any other CI.
- [Push to excubia-app](./docs/guides/excubia-app.md): from the scheduler and after a deploy.
- [Runtime monitoring](./docs/guides/runtime-monitoring.md): request, query, job, task and outgoing HTTP aggregates, plus opt-in memory, queue wait, cache, mail, notification, log level and PHP deprecation counts, N+1 duplicate query detection, Artisan command runs, server CPU, memory and disk metrics, minute-level health samples for failure alerts, Laravel AI SDK usage (tokens, latency, failures and tool calls) Laravel Octane workers (restarts, requests, errors and memory growth per worker) Laravel Reverb servers (connections, messages, errors and channels per app), Laravel Cashier webhooks (received, handled, failed and payment signals per provider and event type) and authentication (logins, failed logins, lockouts and Sanctum token authentications per guard).
- [Exception capture](./docs/guides/exception-capture.md): masked exceptions grouped by fingerprint.
- [Health endpoint](./docs/guides/health-endpoint.md): Oh Dear application health JSON.
- [Privacy](./docs/guides/privacy.md): exactly what leaves the machine, per feature.
- [Upgrading from 0.x to 1.0](./docs/guides/upgrading.md).

## Privacy

By default only package names and versions (and runtime versions) leave the machine, and only to the public data sources in the check table. No file contents, `.env` values, source code, paths or hostnames. Private packages (path, VCS, Satis, Private Packagist, `file:` or other-registry origins in the lockfile, plus `privacy.skipPackages`) are never sent and show as `skipped`. No telemetry. Each opt-in that sends data to your own `excubia-app` is listed field by field in the [privacy guide](./docs/guides/privacy.md).

## Versioning and support

Excubia follows SemVer. Public contracts change only in a major; deprecated options keep working with a warning for at least one minor. In `0.x`, minors may still break and only the latest minor gets fixes. Policy and supported versions: [`docs/VERSIONING.md`](./docs/VERSIONING.md). The list of public contracts, with their files, version fields and contract tests: [`docs/CONTRACTS.md`](./docs/CONTRACTS.md); everything else (PHP classes, message wording, table and Markdown layout) is internal.

## Contributing

See [`CONTRIBUTING.md`](./CONTRIBUTING.md). Scope is defined by [`docs/SPEC.md`](./docs/SPEC.md); new ideas need a `needs-decision` issue first.

## License

[MIT](./LICENSE)
