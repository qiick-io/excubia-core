# Changelog

All notable changes to **Excubia** (`qiick/excubia-core`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Six configuration posture checks, run by `php artisan excubia:check` and `excubia:push` on the booted app (#73): `laravel-debug-mode` (`app.debug` on outside `local` / `testing`: fail, high), `laravel-production-caches` (config, route or event cache missing in `production`: warn, low, one row per cache), `laravel-pending-migrations` (fail, medium), `laravel-failed-jobs` (warn, medium; high once the oldest is 24 hours old), `laravel-scheduler-heartbeat` (no heartbeat for more than 10 minutes: warn, medium) and `laravel-maintenance-mode` (warn, medium). `vendor/bin/excubia` cannot boot the app and reports them as `skipped` ("requires Artisan", `not-applicable`). Findings carry only booleans, counts, ages and names. New check ids, registered after the existing ones; `--only`, `--skip` and ignore rules work as for the other checks.
- `php artisan excubia:heartbeat`, scheduled every minute by the service provider: stores the Unix time under the cache key `excubia:scheduler-heartbeat` for `laravel-scheduler-heartbeat`. It is the only write of the posture checks.
- `excubia.json` keys `checks.laravel-scheduler-heartbeat.staleAfterMinutes` (default `10`) and `checks.laravel-failed-jobs.highSeverityAfterHours` (default `24`), in `excubia.schema.json`.
- SARIF locations for the posture checks: `config/app.php`, `config/queue.php`, `database/migrations` or `routes/console.php`.
- Opt-in runtime monitoring for Laravel apps (#75), off by default: `excubia.json` key `runtime` (`enabled`, `bufferMaxBytes`, and per type `requests`, `slowQueries` with `thresholdMs`, `jobs`, `scheduler`, `outgoingHttp`, each with `enabled` and `sampleRate`). When enabled, the provider listens to `RequestHandled`, `QueryExecuted`, `JobProcessing` / `JobProcessed` / `JobFailed`, `ScheduledTaskFinished` / `ScheduledTaskFailed` / `ScheduledTaskSkipped` and the HTTP client's `ResponseReceived`, aggregates per minute in memory and writes to a size-capped buffer under `storage/excubia/runtime/` (oldest entries dropped first); disabled, no listener is registered. Errors inside the collection never reach the app.
- `php artisan excubia:push-runtime`, scheduled every minute when runtime monitoring is on: sends the buffer to `POST {url}/api/runtime` with the push URL, token and transport. Envelope `protocolVersion: 1`, `type: "runtime"`, `batchId` kept across retries until the app answers 2xx, payload documented in `docs/runtime-schema.json` and referenced from `docs/push-schema.json` (`$defs.runtimeEnvelope`). Exit codes `0` / `2` / `3` as for `push`.
- `--strict` on `check` and `push` (and `excubia:check` / `excubia:push`), also the `excubia.json` key `strict`: a check skipped because a data source failed (network down and no cache, or an API error) makes the command exit `3`, with a message naming each check and data source. `push --strict` then sends nothing. Deliberate skips (`--offline` without cache, `--only` / `--skip`, Laravel checks on a non-Laravel project, ignore rules) never exit `3`. Precedence `2` > `1` > `3`. The flag can only turn strict mode on. Without `--strict` nothing changes.
- Optional finding field `skipReason` in the JSON report (`data-source-failure`, `offline`, `not-applicable`), set on every `skipped` finding and documented in `docs/report-schema.json`. Additive, so `schemaVersion` stays `1`.
- `HealthCheckRunnerService::runForSnapshot()` runs the checks on a `ProjectSnapshotData` built by the caller, without reading a project directory.
- `vendor/bin/excubia push [path]` and `php artisan excubia:push` run the checks and send the report to your own `excubia-app` (`POST {url}/api/reports`, `Authorization: Bearer`). Envelope `protocolVersion: 1` with a unique `batchId` and `sentAt`, documented in `docs/push-schema.json`. Exit codes: `0` sent, `2` configuration error, `3` token rejected or app unreachable.
- `excubia.json` key `app` (`url`, `token`); `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` override it. HTTPS only, plain HTTP allowed for localhost.
- `--format=sarif` writes a SARIF 2.1.0 log for GitHub code scanning: one rule per check id, one result per `fail` or `warn` finding (`critical`/`high` → `error`, `medium` → `warning`, `low`/`info` → `note`, Excubia severity in `properties.severity`), the manifest as a project-relative location, and `partialFingerprints` that stay stable across runs.
- Composite GitHub Action (`action.yml`, `qiick-io/excubia-core@v1` once tagged): installs Excubia from the action's own checkout in `$RUNNER_TEMP`, outside the scanned project, runs `excubia check --format=sarif`, writes the Markdown report to the job summary and uploads the SARIF log with `github/codeql-action/upload-sarif`. Inputs `path`, `fail-on` (default `critical`), `only`, `skip`, `github-token`, `upload-sarif` (default `true`) and `php-version` (default `8.4`); the step fails according to `fail-on` after the upload. Tested by `.github/workflows/action-test.yml`.
- Opt-in health endpoint in the Oh Dear application health JSON format for Laravel apps (`docs/health-endpoint.md`): `excubia.json` keys `healthEndpoint.enabled` (default `false`) and `healthEndpoint.path` (default `excubia/health`), secret from `EXCUBIA_HEALTH_SECRET` (the route is not registered without it), checked against the `oh-dear-health-check-secret` header with `hash_equals`; a wrong or missing secret gets `404`. The endpoint serves the result the last `excubia:check` / `excubia:push` stored in `storage/excubia/health.json` and never runs checks in the request. `pass` → `ok`, `warn` → `warning`, `fail` → `failed`, `skipped` → `skipped`, a run without a report → one `crashed` entry; no stored result yet → one `skipped` entry.

## [0.1.1] - 2026-11-01

### Added

- Complete public README with badges, install and usage, eight-check table plus upgrade-plan note, config pointer, and links.
- Excubia README header cards (light/dark).

### Changed

- Display name is **Excubia** alone (not “qiick Excubia”) in README, CLI banner/`--version`, docs headings, and user-facing strings. Package remains `qiick/excubia-core`.

## [0.1.0] - 2026-11-01

### Added

- Initial `qiick/excubia-core` release: standalone `vendor/bin/excubia check` CLI, optional Artisan alias `excubia:check`, nine health checks, `table` / `json` / `markdown` formats, `excubia.json` config, and local response cache.

[Unreleased]: https://github.com/qiick-io/excubia-core/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/qiick-io/excubia-core/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/qiick-io/excubia-core/releases/tag/v0.1.0
