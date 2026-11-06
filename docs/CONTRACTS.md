# Excubia — Public contracts

The contracts below are **stable from 1.0.0-beta** (#76). After the beta only backwards-compatible changes land before 1.0.0; from 1.0.0 an incompatible change waits for a major, as [`docs/VERSIONING.md`](./VERSIONING.md) describes. Additions (a new check id, option, optional field or config key) are allowed in a minor.

Each contract has a contract test. Most of them compare the code with the committed snapshot [`tests/Fixtures/Contracts/public-contracts.json`](../tests/Fixtures/Contracts/public-contracts.json), so a change fails CI until someone edits that file on purpose.

## Contracts

| Contract | Source file(s) | Version field | Stability | Contract test |
| --- | --- | --- | --- | --- |
| Report JSON (`--format=json`, push `report` payload) | [`docs/report-schema.json`](./report-schema.json), `src/Renderers/JsonReportRenderer.php` | `schemaVersion` (`1`), schema `x-excubia-version` `1` | stable | `tests/Feature/PublicContractsTest.php` (schema hash), `tests/Unit/Schema/ReportSchemaTest.php`, `tests/Feature/ReportDetailsTest.php` |
| Check ids and their run order (list below) | `src/Checks/*Check.php`, `src/Services/HealthCheckRunnerService.php` | — (additions in a minor) | stable | `PublicContractsTest` (`checkIds`), `tests/Feature/DocumentationCoverageTest.php` |
| Exit codes of `check`, `push`, `excubia:push-runtime` (below) | `src/Enums/ExitCodeEnum.php`, `src/Console/CheckCommand.php`, `src/Console/PushCommand.php`, `src/Laravel/ExcubiaPushRuntimeCommand.php` | — | stable | `PublicContractsTest` (`exitCodes`), `tests/Feature/StrictModeTest.php` (precedence), `tests/Feature/PushCommandTest.php`, `tests/Laravel/PushRuntimeCommandTest.php` |
| `bin/excubia` commands, arguments and options | `bin/excubia`, `src/Console/CheckCommand.php`, `src/Console/PushCommand.php`, `src/Console/ClearCacheCommand.php` | — | stable | `PublicContractsTest` (`cli`), `tests/Feature/CliTest.php` |
| Artisan commands and options | `src/Laravel/ExcubiaCheckCommand.php`, `ExcubiaPushCommand.php`, `ExcubiaPushRuntimeCommand.php`, `ExcubiaHeartbeatCommand.php` | — | stable | `PublicContractsTest` (`artisan`), `tests/Laravel/ArtisanCommandsTest.php` |
| Scheduled entries and the heartbeat cache keys | `src/Laravel/ExcubiaServiceProvider.php`, `src/Laravel/ExcubiaHeartbeatCommand.php`, `src/Laravel/QueueWorkerHeartbeatService.php` | — | stable | `tests/Laravel/ScheduleAndRouteContractTest.php` (`laravel`), `PublicContractsTest` (`artisan.heartbeatCacheKey`, `artisan.workerHeartbeatCacheKeyPrefix`) |
| `excubia.json` keys | [`excubia.schema.json`](../excubia.schema.json), `src/Configuration/ExcubiaConfigurationLoader.php` | `x-excubia-version` `1` | stable | `PublicContractsTest` (schema hash), `tests/Feature/ExcubiaConfigurationLoaderTest.php`, `DocumentationCoverageTest` |
| Environment variables | `src/Console/PushCommand.php`, `src/Laravel/HealthEndpointService.php`, `src/Laravel/ExcubiaPushRuntimeCommand.php`, `src/Services/GitHubRepositoryApiService.php`, `src/Services/ReleaseService.php` | — | stable | `PublicContractsTest` (`environmentVariables`), `DocumentationCoverageTest` |
| Push envelope and endpoints | [`docs/push-schema.json`](./push-schema.json), `src/Services/ReportPushService.php` | `protocolVersion` (`1`), schema `x-excubia-version` `1` | stable | `PublicContractsTest` (schema hash, `push`), `ReportSchemaTest`, `PushCommandTest` |
| Runtime batch payload | [`docs/runtime-schema.json`](./runtime-schema.json), `src/Laravel/RuntimeCollectorService.php`, `src/Enums/RuntimeTypeEnum.php` (histogram bounds) | envelope `protocolVersion`, schema `x-excubia-version` `1` | stable | `PublicContractsTest` (schema hash, `push.runtimeHistogramBoundsMs`), `tests/Laravel/RuntimeMonitoringTest.php` |
| Exception batch payload | [`docs/exception-schema.json`](./exception-schema.json), `src/Laravel/ExceptionPayloadService.php` | envelope `protocolVersion`, schema `x-excubia-version` `1` | stable | `PublicContractsTest` (schema hash), `tests/Laravel/ExceptionPayloadTest.php` |
| Health endpoint response, header and path | [`docs/health-endpoint.md`](./health-endpoint.md), `src/Laravel/HealthEndpointService.php`, `src/Renderers/OhDearHealthRenderer.php` | — (Oh Dear application health format) | stable | `PublicContractsTest` (`healthEndpoint`), `ScheduleAndRouteContractTest` (`laravel.routes`), `tests/Laravel/HealthEndpointTest.php` |
| SARIF rule ids, fingerprint key and report `fingerprint` | `src/Renderers/SarifRenderer.php`, `src/Data/FindingData.php` | SARIF `2.1.0`, key `excubiaFinding/v1` | stable | `PublicContractsTest` (`checkIds`, `sarif`), `tests/Feature/SarifReportTest.php` |
| GitHub Action inputs, defaults and outputs | [`action.yml`](../action.yml) | the action ref (`@v1`) | stable | `PublicContractsTest` (`action`), `.github/workflows/action-test.yml`, `tests/Feature/ActionInstallLockTest.php` |
| `skipReason` values | `src/Enums/SkipReasonEnum.php`, `docs/report-schema.json` | report `schemaVersion` | stable | `PublicContractsTest` (`skipReasons`), `ReportSchemaTest` |

## Details

### Check ids

In run order: `php-eol`, `laravel-eol`, `node-eol`, `composer-advisories`, `npm-advisories`, `composer-abandoned`, `composer-stale`, `laravel-next-blocker`, `laravel-debug-mode`, `laravel-production-caches`, `laravel-pending-migrations`, `laravel-failed-jobs`, `laravel-scheduler-heartbeat`, `laravel-maintenance-mode`, `laravel-app-key`, `laravel-session-security`, `laravel-cors-wildcard`, `laravel-production-drivers`, `laravel-log-level`, `laravel-dev-packages`, `dependency-licenses`, `laravel-database`, `laravel-cache`, `laravel-storage`, `laravel-disk-space`, `laravel-queue-backlog`, `php-platform-requirements`, `env-example-sync`, `composer-outdated`, `laravel-queue-workers`, `npm-outdated`, `laravel-backups` (the seventeen before `laravel-backups` added in 1.0.0-beta.2 and `laravel-backups` in 1.0.0-beta.3, additive). The id and its meaning (what it checks) are the contract; its default severity can change in a minor (`docs/VERSIONING.md`). New check ids are appended, so every existing id keeps its position. `upgrade-plan` is a report section, not a check id.

### Exit codes

| Code | `ExitCodeEnum` | `check` | `push` | `excubia:push-runtime` |
| --- | --- | --- | --- | --- |
| `0` | `NoFindingsAtOrAboveFailOn` | no finding at or above `--fail-on` | sent | sent, nothing to send, or both opt-ins off |
| `1` | `FindingsAtOrAboveFailOn` | a finding at or above `--fail-on` | — | — |
| `2` | `UsageOrConfigurationError` | usage or configuration error | configuration error | configuration error |
| `3` | `DataSourceFailure` | strict mode only: a check skipped because a data source failed | app rejected the token, answered an error or was unreachable; strict mode: a data source failure (nothing sent) | app rejected the token, answered an error or was unreachable (the batch is kept) |

Precedence when several apply: `2` > `1` > `3`. The Artisan aliases `excubia:check` and `excubia:push` return the same codes as `check` and `push`.

### Commands and options

- `bin/excubia check [path]`: `--format` (`table`, `json`, `markdown`, `sarif`), `--output`, `--fail-on` (`critical`, `high`, `medium`, `low`, `none`), `--only`, `--skip`, `--no-dev`, `--offline`, `--no-cache`, `--config`, `--no-banner`, `--strict`.
- `bin/excubia push [path]`: `--config`, `--strict`, `--deploy` (#129).
- `bin/excubia cache:clear [path]`: `--config`. It is the only cache command, and it exists only in `bin/excubia`, not in Artisan.
- `php artisan excubia:check`: the `check` options, no `path` (the app base path).
- `php artisan excubia:push`: `--config`, `--strict`, `--deploy` (#129).
- `php artisan excubia:push-runtime`: `--config`.
- `php artisan excubia:heartbeat`: no options.

Symfony Console's global options (`-q`, `-v`, `--version`, …) come with the framework and are not frozen by Excubia.

### Scheduled entries and heartbeat keys

The service provider schedules `excubia:heartbeat` every minute (`* * * * *`), always, and `excubia:push-runtime` every minute when `runtime.enabled` or `exceptions.enabled` is true. The heartbeat stores the Unix time under the cache key `excubia:scheduler-heartbeat` in the default cache store.

The provider also always registers the queue worker heartbeat (#151, additive): a queue worker stores the Unix time under `excubia:worker-heartbeat:<connection>:<queue>` in the default cache store, at most once a minute per connection and queue, for `laravel-queue-workers`. The prefix `excubia:worker-heartbeat:` is in the snapshot (`artisan.workerHeartbeatCacheKeyPrefix`).

### Configuration and environment

- `excubia.json` keys: every key in `excubia.schema.json` (`failOn`, `includeDev`, `strict`, `checks.*`, `ignore[]`, `cache`, `release`, `app`, `privacy`, `healthEndpoint`, `runtime`, `exceptions`), with the types and defaults in the [configuration reference](./guides/configuration.md). Unknown keys stay a configuration error.
- Environment variables: `EXCUBIA_APP_URL` and `EXCUBIA_APP_TOKEN` (override `app.url` / `app.token`), `EXCUBIA_RELEASE` (release identifier, #129), `EXCUBIA_HEALTH_SECRET` (health endpoint secret, at least 32 characters), `EXCUBIA_SERVER_NAME` (server name of `server` runtime aggregates, wins over `runtime.server.name`, #162; an invalid value falls back to the hashed id with a warning), `EXCUBIA_INSIDE_SCHEDULER` (set to `1` by Excubia itself while `schedule:run`, `schedule:work` or `schedule:test` runs, so the task processes it starts are not counted as `command`, #152; not meant to be set by hand), `GITHUB_TOKEN` (optional, GitHub API rate limit). The release is also read, in this order, from `GITHUB_SHA`, `CI_COMMIT_SHA`, `VERCEL_GIT_COMMIT_SHA`, `FORGE_DEPLOY_COMMIT` and `LARAVEL_CLOUD_COMMIT` (`ReleaseService::PLATFORM_VARIABLES`); this list and order are part of the contract.

### Push protocol

Envelope `protocolVersion: 1` with `batchId` (UUID v4) and `sentAt`, `Authorization: Bearer <token>`:

| Endpoint | `type` | Payload |
| --- | --- | --- |
| `POST {url}/api/reports` | — | `report` (`docs/report-schema.json`) |
| `POST {url}/api/runtime` | `runtime` | `runtime` (`docs/runtime-schema.json`, `bucketSeconds` `60`) |
| `POST {url}/api/exceptions` | `exceptions` | `exceptions` (`docs/exception-schema.json`) |

Optional, additive fields from #129 (versions stay `1`): report `release` and `deployedAt` (`push --deploy`), `runtime.release` and `exceptions.release` (one value per batch, not part of the exception fingerprint).

Additive runtime aggregate types from #132 (versions stay `1`): `requestMemory`, `jobMemory`, `queueWait`, `cache`, `mail`, `notification` and `logLevel`, with the metric fields `totalMb`, `maxMb`, `hits`, `misses`, `writes` and `deletes`, and the `excubia.json` keys `runtime.requestMemory`, `runtime.jobMemory`, `runtime.queueWait`, `runtime.cache`, `runtime.mail`, `runtime.notifications` and `runtime.logLevels` (each `enabled`, default `false`, and `sampleRate`). The five 1.0.0-beta.1 types keep their shape and their default (on). An app must accept a runtime batch that holds an aggregate type it does not know and skip those entries, so a newer `excubia-core` can push to an older app; the new types are off by default so an older app does not receive them unless the user turns them on.

Additive runtime aggregate type from #133 (versions stay `1`): `deprecation`, with the dimensions `source`, `message` and `phpVersion`, and the `excubia.json` key `runtime.deprecations` (`enabled`, default `false`, and `sampleRate`).

Additive latency histogram from #147 (versions stay `1`): an optional `histogram` metric field on the `request`, `slowQuery`, `job`, `scheduledTask`, `outgoingHttp` and `queueWait` aggregates, `$defs.histogram` in `docs/runtime-schema.json`. It is a list of 12 integer counts that add up to `count`. The bucket upper bounds are part of the contract: `5`, `10`, `25`, `50`, `100`, `250`, `500`, `1000`, `2500`, `5000` and `10000` ms, inclusive, then one overflow bucket (above `10000` ms). They are the same for every type and are defined once, as `RuntimeTypeEnum::HISTOGRAM_BOUNDS_MS`; the schema's `x-excubia-bucket-bounds-ms` and the snapshot's `push.runtimeHistogramBoundsMs` are checked against it. Two histograms merge by adding them element by element. When only one side of a merge has a histogram, or the lengths differ, the merged entry has none, so a histogram never disagrees with `count`; an app must accept entries without one. Percentiles are linear interpolation inside a bucket, with the recipe in the schema.
Additive runtime aggregate type from #148 (versions stay `1`): `duplicateQuery`, with the dimensions `origin` (`request` or `job`), `name`, `sql` (normalised: literals as `?`, `IN` lists as `IN (…)`, whitespace collapsed, at most 2000 characters) and `connection`, the metric fields `totalRepeats` (summed on merge) and `maxRepeats` (larger wins on merge), and the `excubia.json` key `runtime.duplicateQueries` (`enabled`, default `false`, `sampleRate`, and `threshold`, integer at least `2`, default `5`). `count` is the number of requests or jobs in which the shape ran at least `threshold` times. The `slowQuery` `sql` is unchanged.
Additive runtime aggregate type from #149 (versions stay `1`): `server`, with the dimension `server` (`EXCUBIA_SERVER_NAME`, #162, else `runtime.server.name`, else 12 hex characters of sha256(hostname + sha256(app key)); never the hostname), the nullable gauge fields `loadAverage1`, `loadAverage5`, `loadAverage15`, `cpuCores`, `memoryUsedMb`, `memoryTotalMb`, `diskUsedGb` and `diskTotalGb` (the larger value wins on merge; null only when both sides are null), and the `excubia.json` key `runtime.server` (`enabled`, default `false`, `sampleRate`, and `name`, 1 to 64 of `A-Z a-z 0-9 . _ -`). One sample per `excubia:push-runtime` run on each server.

Additive container-aware server metrics from #173 (versions stay `1`): two optional fields on the `server` aggregate. `cpuLimit` is a nullable gauge (number, may be fractional, the larger value wins on merge): the cgroup CPU quota in cores, null without a quota. `isContainer` is a boolean metric field, not a dimension: true when a cgroup memory limit or CPU quota applies; on merge, true when either side is true, and a missing value counts as false. With a cgroup memory limit below the machine's memory, `memoryUsedMb` and `memoryTotalMb` are the container's (usage minus inactive page cache, out of the limit) instead of the host's. `cpuCores` stays an integer and keeps its meaning (logical cores from `/proc/cpuinfo`). Entries written before #173 have neither field; an app reads a missing `isContainer` as false and a missing `cpuLimit` as null.

Additive runtime aggregate type from #152 (versions stay `1`): `command`, Artisan command runs with the dimensions `command` (the command name, never arguments or options) and `exitCodeClass` (`"0"`, `"1"` or `"other"`), the duration metric fields `totalMs` and `maxMs` with the optional `histogram`, `errorCount` for a non-zero exit code, and the `excubia.json` key `runtime.commands` (`enabled`, default `false`, `sampleRate`, and `ignore`, a list of command names or `*` patterns that replaces the default `schedule:run`, `schedule:work`, `queue:work`, `queue:listen`, `horizon*`, `tinker`; `excubia:*` is always excluded). Only the top-level command of a process counts, and commands run by the scheduler stay `scheduledTask` only.

### Health endpoint

`GET /excubia/health` by default (`healthEndpoint.path`), route name `excubia.health`, secret in the `oh-dear-health-check-secret` header compared with `EXCUBIA_HEALTH_SECRET`; a wrong or missing secret is `404`. The response is `{finishedAt, checkResults[]}` with `name`, `label`, `status`, `notificationMessage`, `shortSummary`, `meta` per entry; statuses `ok`, `warning`, `failed`, `skipped`, `crashed`; `excubia-run` for the crashed and not-run-yet entries. The wording of `notificationMessage` and `shortSummary` is not frozen. Full description: [`docs/health-endpoint.md`](./health-endpoint.md).

### SARIF and fingerprints

One SARIF rule per check id (the rule id is the check id), one result per `fail` or `warn` finding, `partialFingerprints` key `excubiaFinding/v1`. The value, and the report's per-finding `fingerprint`, is `sha256(checkId + "\0" + subject + "\0" + (advisoryId ?? ""))` as lowercase hex. SARIF levels follow the severity (`critical` and `high` → `error`, `medium` → `warning`, `low` and `info` → `note`); locations are project-relative manifest paths (`npm-advisories`: the JavaScript lockfile the report read, `package-lock.json`, `yarn.lock` or `pnpm-lock.yaml`; `dependency-licenses`: `composer.lock` or that JavaScript lockfile, by the finding's ecosystem; `env-example-sync`: `.env.example`).

Additive schedule inventory from #146 (versions stay `1`): an optional top-level report `schedule`, set by `php artisan excubia:check` and `excubia:push` only, one `{taskId, task, expression, timezone, withoutOverlapping, onOneServer, runsInMaintenanceMode, environments}` per event of the app's schedule (`$defs.scheduledTask` in `docs/report-schema.json`), plus an optional `lateAfterMinutes` with the configured grace; an optional `taskId` dimension on the runtime `scheduledTask` aggregates; and the `excubia.json` key `checks.laravel-scheduler-heartbeat.lateAfterMinutes` (integer ≥ 1, default `5`), sent on each entry; an app applies `5` when an entry has none. `task` uses the same naming as the runtime aggregates (#109), and `taskId` is `sha256(task + "\0" + expression)` as lowercase hex in both places, so an app matches runs to schedule entries by it. The naming and the `taskId` recipe are part of the contract. Excubia does not compute missed runs; the app does, from the inventory and the runs.

Additive report fields from #21 (`schemaVersion` stays `1`): top-level `javascriptLockfile` and `npm-advisories` `details.lockfile`, both `package-lock.json`, `yarn.lock` or `pnpm-lock.yaml`.

### GitHub Action

Inputs (default): `path` (`.`), `fail-on` (`critical`), `only` (empty), `skip` (empty), `github-token` (empty), `upload-sarif` (`true`), `php-version` (`8.4`). Outputs: `sarif-file`, `exit-code` (the `check` exit code above).

### `skipReason`

`data-source-failure`, `offline`, `not-applicable`. Only `data-source-failure` can make strict mode exit `3`.

## Internal

Everything not in the table is internal and can change in any release:

- PHP classes, interfaces, enums and their methods under `src/`, including the ones named above as source files. Do not extend or call them; use the CLI, Artisan commands, JSON and schemas.
- The wording of finding messages, fix hints, stderr messages and health `notificationMessage` / `shortSummary` texts.
- The layout of the table and Markdown output, the banner, and the SARIF `message.text`, `name` and `helpUri`.
- File names and formats of the local buffers and stores under `storage/excubia/` and of the response cache.
- Results that change because an upstream data source changed.

## Changing a contract

`PublicContractsTest` and `ScheduleAndRouteContractTest` fail with a message that names this file. If the change is deliberate:

1. Check [`docs/VERSIONING.md`](./VERSIONING.md): an addition is a minor, a removal or an incompatible change waits for a major (with a deprecation first).
2. Update `tests/Fixtures/Contracts/public-contracts.json` with the new values from the test failure.
3. Update this table, the user docs and the schema `description`s, and add a CHANGELOG entry.
4. When a schema's version field changes (`schemaVersion`, `protocolVersion`), bump its `x-excubia-version` too.
