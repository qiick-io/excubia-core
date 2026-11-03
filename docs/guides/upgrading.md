# Upgrading from 0.x to 1.0

1.0 adds features but keeps what 0.1.x did. Most projects only need to update the constraint:

```bash
composer require --dev qiick/excubia-core:^1.0
```

Use the same command without `--dev` if you turn on one of the opt-ins that run in the deployed app. Then read the list below; the items marked **check** may change what your CI sees.

## Symfony 8 is supported

`symfony/console` and `symfony/http-client` accept `^7.2 || ^8.0`. On a Laravel 13 app that already locks Symfony 8 components, `composer require` works without `-W`, and Composer no longer downgrades the app's own Symfony packages. If you added `-W` (`--with-all-dependencies`) to install 0.x or an earlier 1.0 pre-release, drop it. Symfony 8 needs PHP 8.4 or newer; on PHP 8.2 and 8.3 Composer keeps Symfony 7, which still works.

## Laravel 11 is no longer supported for the Artisan integration (D19)

The Artisan commands and the opt-in features need Laravel 12 or 13. Laravel 11 is past security support, and Composer blocks its releases by default, so it cannot be tested.

- On a Laravel 11 app, upgrade Laravel first, or keep using only the standalone CLI: install Excubia in a separate directory (as the [GitHub Action](./ci.md#github-action) does) and run `vendor/bin/excubia check /path/to/app`. The standalone CLI still scans Laravel 11 projects and reports them as end of life.
- The package floor stays PHP `^8.2`.

## New check ids (check)

Six posture checks run through `php artisan excubia:check` and `excubia:push` (not through `vendor/bin/excubia`, where they are `skipped`):

`laravel-debug-mode`, `laravel-production-caches`, `laravel-pending-migrations`, `laravel-failed-jobs`, `laravel-scheduler-heartbeat`, `laravel-maintenance-mode`.

If you run `php artisan excubia:check` in CI with the default `--fail-on=high`, `laravel-debug-mode` fails when `APP_DEBUG=true` outside `local` / `testing`, and `laravel-failed-jobs` can reach high. Either run `vendor/bin/excubia check` in CI, `--skip` the posture checks there, or accept a row with an `ignore` rule. See the [check table](../../README.md#checks).

## A scheduled heartbeat (check)

The service provider now schedules `php artisan excubia:heartbeat` every minute. It writes one cache key, `excubia:scheduler-heartbeat`, in the default cache store and nothing else. It appears in `php artisan schedule:list`. It needs no setup beyond the usual `schedule:run` cron.

## New report fields

The JSON report keeps `schemaVersion` `1`. New optional field: `skipReason` on every `skipped` finding (`data-source-failure`, `offline`, `not-applicable`). Consumers must ignore fields they do not know, as [`docs/VERSIONING.md`](../VERSIONING.md) says.

## Strict mode and exit code 3

`--strict` (or `"strict": true`) makes a check that was skipped because a data source failed exit `3`. Without it nothing changes: such checks stay `skipped` and the exit code ignores them. Precedence is `2` > `1` > `3`.

## Private packages are not checked (check)

Excubia no longer sends the names of private packages to Packagist, OSV.dev or GitHub (#108). A package is private when the lockfile shows it was not served by Packagist or the public npm registry (path, artifact, VCS, Satis, Private Packagist, `file:`, links, workspace members, other registries), or when it matches `privacy.skipPackages`.

`composer-advisories`, `npm-advisories`, `composer-abandoned`, `composer-stale` and `laravel-next-blocker` add one `skipped` row with subject `private packages` naming them, so a check that used to `pass` can now show `skipped`. It never fails and `--strict` ignores it (`skipReason` `not-applicable`). To accept the row, add an `ignore` rule with that subject. See [privacy](./privacy.md#private-packages).

## New `excubia.json` keys

All optional, all off or unchanged by default. An existing 0.1.x file stays valid.

| Key | For |
| --- | --- |
| `strict` | Strict mode |
| `checks.laravel-scheduler-heartbeat.staleAfterMinutes` | Posture check threshold, default `10` |
| `checks.laravel-failed-jobs.highSeverityAfterHours` | Posture check threshold, default `24` |
| `app` | [Push to excubia-app](./excubia-app.md) |
| `healthEndpoint` | [Health endpoint](./health-endpoint.md) |
| `runtime` | [Runtime monitoring](./runtime-monitoring.md) |
| `exceptions` | [Exception capture](./exception-capture.md) |
| `privacy.skipPackages` | [Private packages](./privacy.md#private-packages) never sent to public data sources |

New environment variables: `EXCUBIA_APP_URL`, `EXCUBIA_APP_TOKEN`, `EXCUBIA_HEALTH_SECRET`. Full reference: [configuration](./configuration.md).

## New commands and formats

- `--format=sarif` for GitHub code scanning.
- `vendor/bin/excubia push` and `php artisan excubia:push`.
- `php artisan excubia:push-runtime`, scheduled by the provider only when `runtime.enabled` or `exceptions.enabled` is on.
- `php artisan excubia:heartbeat`, see above.

## GitHub Action

If your workflow installs and runs Excubia by hand, you can replace it with the action, which also uploads SARIF to code scanning:

```yaml
- uses: actions/checkout@v4
- uses: qiick-io/excubia-core@v1
  with:
    fail-on: critical
```

The action's `fail-on` default is `critical`, not the CLI's `high`. See the [CI guide](./ci.md).

## After 1.0

The report JSON, check ids, exit codes, CLI and Artisan options, `excubia.json`, environment variables, push payloads, the health endpoint response and the Action inputs change incompatibly only in a major. New checks, options and optional fields can arrive in minors. See [`docs/VERSIONING.md`](../VERSIONING.md).
