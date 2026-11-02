# Changelog

All notable changes to **Excubia** (`qiick/excubia-core`) are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `HealthCheckRunnerService::runForSnapshot()` runs the checks on a `ProjectSnapshotData` built by the caller, without reading a project directory.

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
