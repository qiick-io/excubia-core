# Configuration reference

Excubia reads one optional file, `excubia.json`, at the root of the scanned project (or the file passed with `--config`), and a few environment variables. Without the file every default below applies.

- The file is JSON so it works without booting Laravel and is easy for agents to edit. JSON Schema: [`excubia.schema.json`](../../excubia.schema.json). Add `"$schema"` to get completion in your editor.
- Unknown keys, wrong types and out-of-range values are a configuration error: the command prints which key and exits `2`.
- In a Laravel app the service provider reads the default `excubia.json` of the app base path once per boot. An invalid file there disables the opt-in features (the app still boots); `php artisan excubia:check` shows the error.
- Command-line options win over the file: `--fail-on` over `failOn`, `--no-dev` over `includeDev`. `--strict` can only turn `strict` on.

## Full example

Every key with its default value, except `$schema`, `ignore` and `app`, which have no default:

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
    "laravel-scheduler-heartbeat": { "staleAfterMinutes": 10, "lateAfterMinutes": 5 },
    "laravel-failed-jobs": { "highSeverityAfterHours": 24 },
    "dependency-licenses": { "allowed": [], "denied": [], "includeDev": false },
    "laravel-database": { "connections": [] },
    "laravel-disk-space": { "warnAtPercentUsed": 80, "failAtPercentUsed": 90 },
    "laravel-queue-backlog": { "maxOldestAgeMinutes": 15, "maxPendingJobs": 1000, "queues": [] },
    "env-example-sync": { "ignoreKeys": [], "useDefaultIgnoreKeys": true },
    "laravel-queue-workers": { "staleAfterMinutes": 5 },
    "laravel-backups": { "maxAgeHours": 26 },
    "laravel-database-connections": { "warnAt": 50, "failAt": 100 },
    "laravel-database-size": { "warnAtMb": 10240 },
    "laravel-redis-memory": { "warnAtPercentUsed": 80, "failAtPercentUsed": 90, "connections": [] }
  },
  "ignore": [
    {
      "checkId": "composer-advisories",
      "subject": "vendor/package",
      "advisoryId": "GHSA-xxxx-xxxx-xxxx",
      "reason": "Not reachable from our code",
      "until": "2027-01-31"
    }
  ],
  "cache": { "directory": null, "ttlHours": 24 },
  "privacy": { "skipPackages": [] },
  "app": { "url": "https://excubia.example.com" },
  "healthEndpoint": { "enabled": false, "path": "excubia/health" },
  "runtime": {
    "enabled": false,
    "bufferMaxBytes": 1048576,
    "requests": { "enabled": true, "sampleRate": 1 },
    "slowQueries": { "enabled": true, "sampleRate": 1, "thresholdMs": 1000 },
    "jobs": { "enabled": true, "sampleRate": 1 },
    "scheduler": { "enabled": true, "sampleRate": 1 },
    "outgoingHttp": { "enabled": true, "sampleRate": 1 },
    "requestMemory": { "enabled": false, "sampleRate": 1 },
    "jobMemory": { "enabled": false, "sampleRate": 1 },
    "queueWait": { "enabled": false, "sampleRate": 1 },
    "cache": { "enabled": false, "sampleRate": 1 },
    "mail": { "enabled": false, "sampleRate": 1 },
    "notifications": { "enabled": false, "sampleRate": 1 },
    "logLevels": { "enabled": false, "sampleRate": 1 },
    "deprecations": { "enabled": false, "sampleRate": 1 },
    "duplicateQueries": { "enabled": false, "sampleRate": 1, "threshold": 5 },
    "server": { "enabled": false, "sampleRate": 1 },
    "commands": { "enabled": false, "sampleRate": 1, "ignore": ["schedule:run", "schedule:work", "queue:work", "queue:listen", "horizon*", "tinker"] },
    "health": { "enabled": false, "sampleRate": 1, "checks": ["laravel-database", "laravel-cache", "laravel-storage", "laravel-disk-space", "laravel-queue-backlog", "laravel-queue-workers", "laravel-scheduler-heartbeat", "laravel-redis-memory", "laravel-maintenance-mode"], "checkBudgetMs": 3000, "totalBudgetMs": 15000 },
    "ai": { "enabled": false, "sampleRate": 1 },
    "aiTools": { "enabled": false, "sampleRate": 1 },
    "octane": { "enabled": false, "sampleRate": 1 },
    "reverb": { "enabled": false, "sampleRate": 1 },
    "billing": { "enabled": false, "sampleRate": 1 },
    "auth": { "enabled": false, "sampleRate": 1 }
  },
  "exceptions": {
    "enabled": false,
    "maskPatterns": [],
    "ignore": [],
    "bufferMaxBytes": 1048576
  }
}
```

## General

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `$schema` | string | — | Schema URL for editors. Ignored by Excubia. |
| `failOn` | `critical`, `high`, `medium`, `low` or `none` | `high` | A finding at or above this severity makes `check` exit `1`. `none` never fails on findings. `--fail-on` wins. |
| `includeDev` | boolean | `true` | Scan `packages-dev` too. `--no-dev` sets it to `false`. |
| `strict` | boolean | `false` | Exit `3` when a check was skipped because a data source failed; `push` then sends nothing. Same as `--strict`, which wins over `false`. |
| `release` | string | — | Release identifier of the deployed code, sent as `release` with reports, runtime and exception batches (see [release identifier](#release-identifier)). Wins over `EXCUBIA_RELEASE` and the CI variables. |

### Release identifier

Reports (`check`, `push` and their Artisan aliases) and runtime and exception batches carry an optional `release`, so `excubia-app` can tie a problem to the deploy that introduced it. It is resolved in this order; the first one set wins:

1. the `release` key of `excubia.json`;
2. `EXCUBIA_RELEASE`;
3. the CI and platform variables, in this order: `GITHUB_SHA`, `CI_COMMIT_SHA`, `VERCEL_GIT_COMMIT_SHA`, `FORGE_DEPLOY_COMMIT`, `LARAVEL_CLOUD_COMMIT`;
4. only for the standalone `vendor/bin/excubia`: the commit checked out in the project directory. Excubia does not run `git`: it reads `.git/HEAD` and the ref file it names (or `packed-refs`), and follows the `gitdir:` pointer of a worktree or submodule. Only the commit id is used; no branch name, author or commit message. Only the project directory itself is looked at, not its parents.

The value is up to 100 characters of `A-Z a-z 0-9 . _ + / @ -`. Anything else is rejected and the release is reported as absent (`null`), without trying the next source. Runtime and exception batches use the release at the time they are sent, without the git step.

## Checks

`checks` holds per-check settings. Only these checks have settings; any other check id here is an error. To turn a check off, use `--skip` (or `node-eol.enabled`).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `checks` | object | `{}` | Per-check settings. |
| `checks.composer-stale` | object | | Settings of `composer-stale`. |
| `checks.composer-stale.staleAfterMonths` | integer ≥ 1 | `24` | Warn (low) when a package had no release for more than this many months. |
| `checks.php-eol` | object | | Settings of `php-eol`. |
| `checks.php-eol.warnDaysBeforeEol` | integer ≥ 0 | `180` | Warn (medium) when PHP security support ends within this many days. |
| `checks.laravel-eol` | object | | Settings of `laravel-eol`. |
| `checks.laravel-eol.warnDaysBeforeEol` | integer ≥ 0 | `180` | Warn (medium) when Laravel security fixes end within this many days. |
| `checks.node-eol` | object | | Settings of `node-eol`. |
| `checks.node-eol.enabled` | boolean | `true` | `false` reports `node-eol` as `skipped` (`not-applicable`). |
| `checks.laravel-scheduler-heartbeat` | object | | Settings of `laravel-scheduler-heartbeat`. |
| `checks.laravel-scheduler-heartbeat.staleAfterMinutes` | integer ≥ 1 | `10` | Warn when the heartbeat written by `excubia:heartbeat` is older than this many minutes. |
| `checks.laravel-scheduler-heartbeat.lateAfterMinutes` | integer ≥ 1 | `5` | Grace for missed-run detection in excubia-app (#146): sent as `lateAfterMinutes` on each report `schedule` entry; a scheduled task counts as late when no run of it started within this many minutes of a due time. Excubia itself does not compute missed runs. |
| `checks.laravel-failed-jobs` | object | | Settings of `laravel-failed-jobs`. |
| `checks.laravel-failed-jobs.highSeverityAfterHours` | integer ≥ 1 | `24` | Failed jobs warn at medium, and at high once the oldest is at least this many hours old. |
| `checks.dependency-licenses` | object | | Settings of `dependency-licenses`, see [below](#dependency-licenses). |
| `checks.dependency-licenses.allowed` | list of strings | `[]` | SPDX license ids or globs that never warn, for example `"GPL-2.0-or-later"`, `"LGPL-*"` or `"proprietary"`. |
| `checks.dependency-licenses.denied` | list of strings | `[]` | SPDX license ids or globs that fail the check at high. Denied wins over allowed. |
| `checks.dependency-licenses.includeDev` | boolean | `false` | Also inspect development dependencies (Composer `packages-dev`, npm `dev` and `devOptional`). `--no-dev` and `"includeDev": false` at the top level still leave Composer `packages-dev` out. |
| `checks.laravel-database` | object | | Settings of `laravel-database`. |
| `checks.laravel-database.connections` | list of strings | `[]` | Connection names from `config/database.php` probed with `select 1` on top of the default connection. A name that is not configured fails like an unreachable one. |
| `checks.laravel-disk-space` | object | | Settings of `laravel-disk-space`. |
| `checks.laravel-disk-space.warnAtPercentUsed` | integer 1–100 | `80` | Warn (medium) when the disk holding `storage/` is at least this many percent used. At most `failAtPercentUsed`. |
| `checks.laravel-disk-space.failAtPercentUsed` | integer 1–100 | `90` | Fail (high) when the disk holding `storage/` is at least this many percent used. |
| `checks.laravel-queue-backlog` | object | | Settings of `laravel-queue-backlog`. |
| `checks.laravel-queue-backlog.maxOldestAgeMinutes` | integer ≥ 1 | `15` | Warn (medium) when the oldest pending job of a queue has waited longer than this many minutes. |
| `checks.laravel-queue-backlog.maxPendingJobs` | integer ≥ 1 | `1000` | Warn (medium) when a queue holds more than this many pending jobs. |
| `checks.laravel-queue-backlog.queues` | list of strings | `[]` | Redis only: queues read on top of the default queue of the default queue connection. The `database` driver reads every queue in its jobs table. |
| `checks.env-example-sync` | object | | Settings of `env-example-sync`, see [below](#env-example-sync). |
| `checks.env-example-sync.ignoreKeys` | list of strings | `[]` | Environment key names or globs that are never reported, neither as missing from `.env.example` nor as unused, for example `"STRIPE_*"`. Added to the default list unless `useDefaultIgnoreKeys` is `false`. |
| `checks.env-example-sync.useDefaultIgnoreKeys` | boolean | `true` | Keep the default list of optional Laravel keys. `false` makes `ignoreKeys` replace it. |
| `checks.laravel-queue-workers` | object | | Settings of `laravel-queue-workers`. |
| `checks.laravel-queue-workers.staleAfterMinutes` | integer ≥ 1 | `5` | Without Laravel Horizon: warn (medium) for a queue that has pending jobs when no worker heartbeat (`excubia:worker-heartbeat:<connection>:<queue>`) is newer than this many minutes. Ignored when Horizon is installed: its own status is read instead. |
| `checks.laravel-backups` | object | | Settings of `laravel-backups`. |
| `checks.laravel-backups.maxAgeHours` | integer ≥ 1 | `26` | Fail (high) for a spatie/laravel-backup destination whose newest backup is older than this many hours. |
| `checks.laravel-database-connections` | object | | Settings of `laravel-database-connections`. |
| `checks.laravel-database-connections.warnAt` | integer ≥ 1 | `50` | Warn (medium) when the default database connection has more open connections than this (MySQL / MariaDB: `Threads_connected` on the server; PostgreSQL: `pg_stat_activity` rows for the database). At most `failAt`. |
| `checks.laravel-database-connections.failAt` | integer ≥ 1 | `100` | Fail (high) when the default database connection has more open connections than this. |
| `checks.laravel-database-size` | object | | Settings of `laravel-database-size`. |
| `checks.laravel-database-size.warnAtMb` | integer ≥ 1 | unset | Warn (medium) when the default database is larger than this many MB. Unset, the size and the five largest tables are only reported (pass, info). |
| `checks.laravel-redis-memory` | object | | Settings of `laravel-redis-memory`. |
| `checks.laravel-redis-memory.warnAtPercentUsed` | integer 1–100 | `80` | Warn (medium) when a Redis connection uses this share of its `maxmemory` or more. Not above `failAtPercentUsed`. |
| `checks.laravel-redis-memory.failAtPercentUsed` | integer 1–100 | `90` | Fail (high) when a Redis connection uses this share of its `maxmemory` or more. |
| `checks.laravel-redis-memory.connections` | list of strings | `[]` | Redis connection names (`database.redis.*`) to read instead of the ones the default cache store, queue connection and session use. Empty reads the ones in use. |

### Dependency licenses

`dependency-licenses` reads the `license` entries of `composer.lock` and `package-lock.json` (lockfileVersion 2 and 3). It sends nothing anywhere. `yarn.lock` and `pnpm-lock.yaml` record no licenses: their packages are left out of the rows and the inventory, and one `skipped` row (`not-applicable`) says how many there are. Each package gets one verdict:

1. **Denied** (fail, high): a license matches `denied`.
2. **Allowed** (no row): a license matches `allowed`.
3. **Strong copyleft** (warn, medium): GPL, AGPL, SSPL, EUPL, OSL, RPL or Sleepycat. LGPL, MPL, EPL and CDDL are weak copyleft and do not warn.
4. **Known** (no row): one of the common SPDX ids Excubia knows (MIT, BSD, Apache, ISC, LGPL, MPL and about 35 more).
5. **Missing or unknown** (warn, low): everything else, such as no license, `UNLICENSED`, `proprietary`, `SEE LICENSE IN …`, an expression that does not parse, or a rarer SPDX id. Add a valid id you accept to `allowed`.

Expressions follow SPDX: `OR` passes when any option passes, `AND` needs every part, parentheses group, and `WITH` exceptions are read as the license they modify. Composer's `license` array is a choice, like `OR`. A `+`, `-only` or `-or-later` suffix is matched both as written and without the suffix, so `"GPL-2.0"` in `allowed` also covers `GPL-2.0-or-later` and `GPL-2.0+`. Globs use `*` for any characters and `?` for exactly one, case-insensitively, against the whole id; only letters, digits, `. + : -` and the two wildcards are allowed.

The JSON report adds a top-level `licenses` inventory with the counts per license and per ecosystem (see the [README](../../README.md#formats)). To accept one package instead of a license, add an `ignore` rule with `checkId` `dependency-licenses` and the package name as `subject`.

### env-example-sync

`env-example-sync` compares the environment keys that `config/*.php` reads with the keys that `.env.example` lists. It sends nothing and reads key names only:

- `config/*.php` (top level) is read with PHP's tokenizer, never included or executed. It finds `env('KEY')`, `\env('KEY')`, `Env::get('KEY')` and `Env::getOrFail('KEY')` with a literal key; computed keys are not seen.
- `.env.example` is read for its key names: `KEY=value`, `export KEY=value` and commented-out assignments such as `# DB_HOST=127.0.0.1` count as listed. Values are dropped while reading.
- `.env` is never opened.

A key read by a config file but missing from `.env.example` warns at low, one row per key (subject the key name). Keys in `.env.example` that no config file reads are one `info` row with subject `unused keys`. The check is skipped (`not-applicable`) when the project has no `.env.example` or no `config/` directory.

Keys matching `ignoreKeys` or the default list are left out in both directions. Globs use `*` for any characters and `?` for exactly one, case-insensitively, against the whole name; only letters, digits, `_` and the two wildcards are allowed. The default list covers the optional keys the Laravel 12 and 13 config files read that a fresh application's `.env.example` does not list, and the keys it lists for tools outside `config/`, so a fresh application reports nothing:

`ABLY_*`, `APP_PREVIOUS_KEYS`, `ARGON_*`, `ASSET_URL`, `AUTH_*`, `AWS_*`, `BCRYPT_LIMIT`, `BCRYPT_ROUNDS`, `BEANSTALKD_*`, `BROADCAST_CONNECTION`, `CACHE_STORAGE_*`, `CONCURRENCY_DRIVER`, `DB_CACHE_*`, `DB_CHARSET`, `DB_COLLATION`, `DB_DIRECT_*`, `DB_FOREIGN_KEYS`, `DB_MASK_BINDINGS`, `DB_POOLED`, `DB_QUEUE*`, `DB_SOCKET`, `DB_SSLMODE`, `DB_URL`, `DYNAMODB_*`, `FRONTEND_URL`, `HASH_DRIVER`, `HASH_VERIFY`, `IMAGE_DRIVER`, `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_TRACE`, `LOG_PAPERTRAIL_*`, `LOG_SLACK_*`, `LOG_STDERR_*`, `LOG_SYSLOG_*`, `MAIL_EHLO_DOMAIN`, `MAIL_LOG_CHANNEL`, `MAIL_MARKDOWN_THEME`, `MAIL_SENDMAIL_PATH`, `MAIL_URL`, `MEMCACHED_*`, `MERCURE_*`, `MYSQL_ATTR_SSL_CA`, `PAPERTRAIL_*`, `PHP_CLI_SERVER_WORKERS`, `POSTMARK_*`, `PUSHER_*`, `QUEUE_FAILED_DRIVER`, `REDIS_BACKOFF_*`, `REDIS_CACHE_*`, `REDIS_CLIENT`, `REDIS_CLUSTER`, `REDIS_COMMAND_RETRIES`, `REDIS_DB`, `REDIS_MAX_RETRIES`, `REDIS_PERSISTENT`, `REDIS_PREFIX`, `REDIS_QUEUE*`, `REDIS_URL`, `REDIS_USERNAME`, `RESEND_*`, `REVERB_*`, `SESSION_CACHE_KEY`, `SESSION_CONNECTION`, `SESSION_COOKIE`, `SESSION_EXPIRE_ON_CLOSE`, `SESSION_HTTP_ONLY`, `SESSION_PARTITIONED_COOKIE`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_STORE`, `SESSION_TABLE`, `SLACK_BOT_USER_*`, `SQS_*`, `VIEW_COMPILED_PATH`, `VITE_*`.

```json
{ "checks": { "env-example-sync": { "ignoreKeys": ["STRIPE_*", "SENTRY_LARAVEL_DSN"] } } }
```

With `"useDefaultIgnoreKeys": false` only `ignoreKeys` applies. To accept one key instead, add an `ignore` rule with `checkId` `env-example-sync` and the key name as `subject`.

## Ignore rules

`ignore` is a list of findings you accept. A rule matches a finding with the same `checkId` and `subject`, and, when `advisoryId` is set, the same advisory. Ignored findings are removed from the report, the exit code and the health endpoint.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `ignore` | list of objects | `[]` | Accepted findings. |
| `ignore[].checkId` | string, required | | Check id, for example `composer-advisories`. |
| `ignore[].subject` | string, required | | The finding subject: a package (`vendor/package`), a runtime (`php`, `laravel/framework`, `node`) or, for the posture checks, `app.debug`, `config-cache`, `route-cache`, `event-cache`, `migrations`, `failed-jobs`, `scheduler` or `maintenance-mode`; for the infrastructure checks the connection, store or queue name, `storage`, `bootstrap/cache`, `disk`, `php` or an `ext-*` name. |
| `ignore[].advisoryId` | string or null | `null` | Match only this advisory (GHSA or CVE id as shown in the report). `null` matches every finding of the subject. |
| `ignore[].reason` | string, required | | Why it is accepted. Printed when the rule expires. |
| `ignore[].until` | `YYYY-MM-DD` or null | `null` | Last day the rule applies (inclusive, UTC). After it the finding is active again and a warning is printed on stderr. `null` never expires. |

## Response cache

Data-source responses are cached on disk so repeated runs and `--offline` work without network.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `cache` | object | | Cache settings. |
| `cache.directory` | string or null | `null` | Cache directory. `null` uses `qiick-excubia-<uid>` in the system temporary directory, so nothing is written to the project; it is skipped when it is a symlink, owned by another user or writable by others. |
| `cache.ttlHours` | integer ≥ 0 | `24` | Age after which a cached response is fetched again. `--offline` uses only responses younger than this; older ones count as missing. |

`--no-cache` bypasses the cache for one run. `vendor/bin/excubia cache:clear` deletes the cache entries (only files named like cache entries).

## Privacy

Packages the lockfile shows as not served by Packagist or the public npm registry are never sent to a public data source; see [private packages](./privacy.md#private-packages). `privacy.skipPackages` adds names the lockfile cannot tell apart, such as a private package published on the public npm registry.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `privacy` | object | | What the checks may send to public data sources. |
| `privacy.skipPackages` | list of strings | `[]` | Package name globs never sent to Packagist, OSV.dev or GitHub, for example `"acme/*"` or `"@acme/*"`. `*` matches any characters (`/` included), `?` exactly one; the whole name must match, case-insensitively. Only letters, digits, `@ / . _ ~ -` and the two wildcards are allowed. Matching packages appear in one `skipped` row per check. |

## excubia-app

The target of `excubia push`, `excubia:push` and `excubia:push-runtime`. See the [push guide](./excubia-app.md).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `app` | object | | Your own `excubia-app`. |
| `app.url` | string | — | Base URL of the app, for example `https://excubia.example.com`. HTTPS, or plain HTTP to `localhost`, `127.0.0.1` or `[::1]` only. `EXCUBIA_APP_URL` wins. |
| `app.token` | string | — | Project push token from the app. `EXCUBIA_APP_TOKEN` wins. Prefer the environment variable so the token is not committed; `check`, `push`, `excubia:check`, `excubia:push` and `excubia:push-runtime` print a warning on stderr when it is set. |

## Health endpoint

See the [health endpoint guide](./health-endpoint.md). The secret is not a key: it comes from `EXCUBIA_HEALTH_SECRET` only.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `healthEndpoint` | object | | Oh Dear-compatible endpoint, Laravel only. |
| `healthEndpoint.enabled` | boolean | `false` | Register the `GET` route (only when `EXCUBIA_HEALTH_SECRET` is set too, at least 32 characters). |
| `healthEndpoint.path` | string | `excubia/health` | URL path of the route. Leading and trailing `/` are ignored. Letters, digits, `.`, `_`, `~`, `-` and `/` only. |

## Runtime monitoring

See the [runtime monitoring guide](./runtime-monitoring.md). Off by default; only `runtime.enabled` is needed to turn it on.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `runtime` | object | | Opt-in runtime aggregates, Laravel only. |
| `runtime.enabled` | boolean | `false` | Register the listeners and schedule `excubia:push-runtime` every minute. |
| `runtime.bufferMaxBytes` | integer ≥ 4096 | `1048576` | Size cap of `storage/excubia/runtime/`. The oldest entries are dropped first. |
| `runtime.requests` | object | | Incoming HTTP requests. |
| `runtime.requests.enabled` | boolean | `true` | Collect requests while `runtime.enabled` is true. |
| `runtime.requests.sampleRate` | number 0–1 | `1` | Share of requests recorded. |
| `runtime.slowQueries` | object | | Database queries at or above the threshold. |
| `runtime.slowQueries.enabled` | boolean | `true` | Collect slow queries. |
| `runtime.slowQueries.sampleRate` | number 0–1 | `1` | Share of slow queries recorded. |
| `runtime.slowQueries.thresholdMs` | integer ≥ 0 | `1000` | A query this slow or slower is recorded. |
| `runtime.jobs` | object | | Queued jobs. |
| `runtime.jobs.enabled` | boolean | `true` | Collect jobs. |
| `runtime.jobs.sampleRate` | number 0–1 | `1` | Share of jobs recorded. |
| `runtime.scheduler` | object | | Scheduled tasks. |
| `runtime.scheduler.enabled` | boolean | `true` | Collect scheduled tasks. |
| `runtime.scheduler.sampleRate` | number 0–1 | `1` | Share of task runs recorded. |
| `runtime.outgoingHttp` | object | | Calls made with Laravel's HTTP client. |
| `runtime.outgoingHttp.enabled` | boolean | `true` | Collect outgoing calls. |
| `runtime.outgoingHttp.sampleRate` | number 0–1 | `1` | Share of outgoing calls recorded. |
| `runtime.requestMemory` | object | | Peak PHP memory of incoming requests, per route name and method. Added in 1.0.0-beta.2, off by default. |
| `runtime.requestMemory.enabled` | boolean | `false` | Collect request peak memory. |
| `runtime.requestMemory.sampleRate` | number 0–1 | `1` | Share of requests measured. |
| `runtime.jobMemory` | object | | Peak PHP memory of queued jobs, per class and queue (the peak is reset when a job starts). Off by default. |
| `runtime.jobMemory.enabled` | boolean | `false` | Collect job peak memory. |
| `runtime.jobMemory.sampleRate` | number 0–1 | `1` | Share of jobs measured. |
| `runtime.queueWait` | object | | Time jobs wait between the push and their first attempt, without their delay. Adds an `excubiaQueuedAt` timestamp to every job payload. Off by default. |
| `runtime.queueWait.enabled` | boolean | `false` | Collect queue wait times. |
| `runtime.queueWait.sampleRate` | number 0–1 | `1` | Share of jobs measured. |
| `runtime.cache` | object | | Cache hits, misses, writes and deletes per store and masked key prefix. Off by default. |
| `runtime.cache.enabled` | boolean | `false` | Collect cache events. |
| `runtime.cache.sampleRate` | number 0–1 | `1` | Share of cache events recorded. |
| `runtime.mail` | object | | Sent mail per mailable and mailer; failures through exception capture only. Off by default. |
| `runtime.mail.enabled` | boolean | `false` | Collect mail counts. |
| `runtime.mail.sampleRate` | number 0–1 | `1` | Share of mails recorded. |
| `runtime.notifications` | object | | Sent and failed notifications per class and channel. Off by default. |
| `runtime.notifications.enabled` | boolean | `false` | Collect notification counts. |
| `runtime.notifications.sampleRate` | number 0–1 | `1` | Share of notifications recorded. |
| `runtime.logLevels` | object | | Log entries counted per level and log channel, never the message. Off by default. |
| `runtime.logLevels.enabled` | boolean | `false` | Collect log level counts. |
| `runtime.logLevels.sampleRate` | number 0–1 | `1` | Share of log entries recorded. |
| `runtime.deprecations` | object | | PHP deprecations counted per source (`app`, `vendor/<vendor>/<package>`), masked message and PHP version, through a chained error handler. Off by default. |
| `runtime.deprecations.enabled` | boolean | `false` | Collect deprecation counts. |
| `runtime.deprecations.sampleRate` | number 0–1 | `1` | Share of deprecations recorded. |
| `runtime.duplicateQueries` | object | | N+1 detection: query shapes (normalised SQL, never bindings) that run at least `threshold` times in one request or job. Off by default. |
| `runtime.duplicateQueries.enabled` | boolean | `false` | Detect duplicate queries. |
| `runtime.duplicateQueries.sampleRate` | number 0–1 | `1` | Share of requests and jobs evaluated. |
| `runtime.duplicateQueries.threshold` | integer ≥ 2 | `5` | Executions of one query shape within one request or job from which it is recorded. |
| `runtime.server` | object | | Server CPU, memory and disk: one sample of this machine per `excubia:push-runtime` run (load average 1, 5 and 15 minutes, CPU cores, memory used and total, disk used and total for the disk that holds `storage/`). Off by default. |
| `runtime.server.enabled` | boolean | `false` | Take server samples. |
| `runtime.server.sampleRate` | number 0–1 | `1` | Share of runs that take a sample. |
| `runtime.server.name` | string | hashed id | Readable server name in excubia-app: 1 to 64 letters, digits, `.`, `_` or `-`. `EXCUBIA_SERVER_NAME` wins. Unset, the server is the first 12 hex characters of sha256(hostname + sha256(app key)); the hostname is never sent. See [runtime monitoring](./runtime-monitoring.md#server-metrics). |
| `runtime.commands` | object | | Artisan command runs per command name and exit code class (`0`, `1`, `other`), with their duration; never arguments or options. Only the top-level command of a process; commands run by the scheduler are `scheduledTask`, and `excubia:*` is never counted. Off by default. |
| `runtime.commands.enabled` | boolean | `false` | Collect command runs. |
| `runtime.commands.sampleRate` | number 0–1 | `1` | Share of command runs recorded. |
| `runtime.commands.ignore` | list of strings | `["schedule:run", "schedule:work", "queue:work", "queue:listen", "horizon*", "tinker"]` | Command names, `*` wildcards allowed, never counted. Setting it replaces the default, so list the defaults you want to keep. |
| `runtime.health` | object | | Minute-level health samples for failure alerts: every `excubia:push-runtime` run, at most once per UTC minute on each server, runs the fast Artisan checks with local probes only and sends their status, severity, finding count and finding fingerprints (no messages). Off by default. See [runtime monitoring](./runtime-monitoring.md#health-samples). |
| `runtime.health.enabled` | boolean | `false` | Take health samples. |
| `runtime.health.sampleRate` | number 0–1 | `1` | Share of runs that take a sample. |
| `runtime.health.checks` | list of check ids | all nine | Checks to sample, in order, without duplicates: any of `laravel-database`, `laravel-cache`, `laravel-storage`, `laravel-disk-space`, `laravel-queue-backlog`, `laravel-queue-workers`, `laravel-scheduler-heartbeat`, `laravel-redis-memory`, `laravel-maintenance-mode`. Any other id is a configuration error. |
| `runtime.health.checkBudgetMs` | integer ≥ 1 | `3000` | Milliseconds reserved for one check: a check starts only while this much is left of `totalBudgetMs`. At most `totalBudgetMs`. |
| `runtime.health.totalBudgetMs` | integer | `15000` | Milliseconds of the push-runtime run, from its start, after which no further check starts; the rest are sent as `skipped`. |
| `runtime.ai` | object | | Laravel AI SDK (`laravel/ai`) operations per operation, agent class, provider and model, with their duration, failures, failovers and token usage. Records nothing unless `laravel/ai` is installed; never prompts, responses or exception messages. Off by default. See [runtime monitoring](./runtime-monitoring.md#laravel-ai-sdk). |
| `runtime.ai.enabled` | boolean | `false` | Collect AI operations. |
| `runtime.ai.sampleRate` | number 0–1 | `1` | Share of AI attempts recorded. |
| `runtime.aiTools` | object | | Laravel AI SDK agent tool calls per agent and tool class, with their duration and failures. Records nothing unless `laravel/ai` is installed; never tool arguments or results. Off by default. |
| `runtime.aiTools.enabled` | boolean | `false` | Collect AI tool calls. |
| `runtime.aiTools.sampleRate` | number 0–1 | `1` | Share of tool calls recorded. |
| `runtime.octane` | object | | Laravel Octane (`laravel/octane`) workers per server, Octane server and worker type: starts, stops, requests, tasks, ticks, worker errors, memory after each request and its growth since the worker's first request, and requests handled at stop. Records nothing unless `laravel/octane` is installed; never URLs, request or task data, or PIDs. Off by default. See [runtime monitoring](./runtime-monitoring.md#laravel-octane). |
| `runtime.octane.enabled` | boolean | `false` | Collect Octane worker events. |
| `runtime.octane.sampleRate` | number 0–1 | `1` | Share of worker events recorded. |
| `runtime.reverb` | object | | Laravel Reverb (`laravel/reverb`) servers per server and Reverb app id: connections opened, rejected and pruned, open connections sampled once a minute, messages received and sent, errors, and channels created and removed by prefix. Records nothing unless `laravel/reverb` is installed; never message payloads, channel names, socket ids or origins. Off by default. See [runtime monitoring](./runtime-monitoring.md#laravel-reverb). |
| `runtime.reverb.enabled` | boolean | `false` | Collect Reverb events. |
| `runtime.reverb.sampleRate` | number 0–1 | `1` | Share of Reverb events recorded (0 also stops the connection sample). |
| `runtime.billing` | object | | Laravel Cashier (`laravel/cashier`, `laravel/cashier-paddle`) webhooks per provider and event type: received, handled, failed, handling time, failed payments, payments needing action and canceled subscriptions. Records nothing unless Cashier is installed; never anything of the payload but its event type. Off by default. See [runtime monitoring](./runtime-monitoring.md#laravel-cashier). |
| `runtime.billing.enabled` | boolean | `false` | Collect Cashier webhook events. |
| `runtime.billing.sampleRate` | number 0–1 | `1` | Share of webhooks recorded. |
| `runtime.auth` | object | | Authentication per guard: successful and failed logins, lockouts and, with `laravel/sanctum`, Sanctum token authentications. Never usernames, emails, user ids, credentials, tokens or IP addresses. Off by default. See [runtime monitoring](./runtime-monitoring.md#authentication). |
| `runtime.auth.enabled` | boolean | `false` | Collect authentication events. |
| `runtime.auth.sampleRate` | number 0–1 | `1` | Share of authentication events recorded. |

## Exception capture

See the [exception capture guide](./exception-capture.md). Off by default and independent of `runtime`.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `exceptions` | object | | Opt-in exception capture, Laravel only. |
| `exceptions.enabled` | boolean | `false` | Add the `reportable()` callback and schedule `excubia:push-runtime` every minute. |
| `exceptions.maskPatterns` | list of strings | `[]` | Extra PCRE patterns with delimiters, for example `"/ORD-[0-9]+/"`. Matches in messages become `[masked]`, before the built-in masking. An invalid pattern is a configuration error. |
| `exceptions.ignore` | list of strings | `[]` | Exception classes never captured, subclasses included, for example `"App\\Exceptions\\PaymentDeclinedException"`. A leading `\` is ignored. |
| `exceptions.bufferMaxBytes` | integer ≥ 4096 | `1048576` | Size cap of `storage/excubia/exceptions/`. The oldest entries are dropped first. |

## Environment variables

Excubia reads these from the process environment (`getenv()`), never from Laravel config. With `php artisan config:cache`, Laravel no longer loads `.env` into the process, so set them in the real environment: the PHP-FPM pool, the container, the systemd unit or the CI job.

| Variable | Used by | Meaning |
| --- | --- | --- |
| `EXCUBIA_APP_URL` | `push`, `excubia:push`, `excubia:push-runtime` | Base URL of your `excubia-app`. Wins over `app.url`. |
| `EXCUBIA_APP_TOKEN` | `push`, `excubia:push`, `excubia:push-runtime` | Project push token. Wins over `app.token`. Never printed, cached or written to a report. |
| `EXCUBIA_RELEASE` | `check`, `push`, their Artisan aliases, `excubia:push-runtime` | Release identifier. `release` in `excubia.json` wins; it wins over `GITHUB_SHA`, `CI_COMMIT_SHA`, `VERCEL_GIT_COMMIT_SHA`, `FORGE_DEPLOY_COMMIT`, `LARAVEL_CLOUD_COMMIT` ([release identifier](#release-identifier)). |
| `EXCUBIA_SERVER_NAME` | `excubia:push-runtime` | Readable name of this server in `server` runtime aggregates (#162): 1 to 64 letters, digits, `.`, `_` or `-`. Wins over `runtime.server.name`. An invalid value is not an error: the server is sent as its hashed id and the command warns on stderr. |
| `EXCUBIA_HEALTH_SECRET` | health endpoint | Secret expected in the `oh-dear-health-check-secret` header, at least 32 characters. Without it, or when it is shorter, the route is not registered. |
| `EXCUBIA_INSIDE_SCHEDULER` | `runtime.commands` | Set to `1` by Excubia itself while `schedule:run`, `schedule:work` or `schedule:test` runs, and inherited by the task processes they start, so a scheduled Artisan command is counted as `scheduledTask` only. Do not set it by hand: every command of a process that has it is left out of `command`. |
| `GITHUB_TOKEN` | `composer-stale` | Optional. Raises the GitHub API rate limit for the archived-repository lookup. Sent only to `api.github.com`; never printed, cached or written to a report. |
| `HTTPS_PROXY` | all HTTP requests | Proxy for the data sources and the push, read by Symfony HttpClient. |
| `NO_COLOR` | `table` format | Turns colours off. |

Empty values count as unset.
