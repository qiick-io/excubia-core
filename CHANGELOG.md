# Changelog

All notable changes to **Excubia** (`qiick/excubia-core`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `HealthCheckRunnerService::runForSnapshot()` runs the checks on a `ProjectSnapshotData` built by the caller, without reading a project directory.
- `vendor/bin/excubia push [path]` and `php artisan excubia:push` run the checks and send the report to your own `excubia-app` (`POST {url}/api/reports`, `Authorization: Bearer`). Envelope `protocolVersion: 1` with a unique `batchId` and `sentAt`, documented in `docs/push-schema.json`. Exit codes: `0` sent, `2` configuration error, `3` token rejected or app unreachable.
- `excubia.json` key `app` (`url`, `token`); `EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` override it. HTTPS only, plain HTTP allowed for localhost.
- `--format=sarif` writes a SARIF 2.1.0 log for GitHub code scanning: one rule per check id, one result per `fail` or `warn` finding (`critical`/`high` → `error`, `medium` → `warning`, `low`/`info` → `note`, Excubia severity in `properties.severity`), the manifest as a project-relative location, and `partialFingerprints` that stay stable across runs.

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
