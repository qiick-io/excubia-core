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
    "laravel-scheduler-heartbeat": { "staleAfterMinutes": 10 },
    "laravel-failed-jobs": { "highSeverityAfterHours": 24 },
    "dependency-licenses": { "allowed": [], "denied": [], "includeDev": false },
    "laravel-database": { "connections": [] },
    "laravel-disk-space": { "warnAtPercentUsed": 80, "failAtPercentUsed": 90 },
    "laravel-queue-backlog": { "maxOldestAgeMinutes": 15, "maxPendingJobs": 1000, "queues": [] }
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
    "outgoingHttp": { "enabled": true, "sampleRate": 1 }
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

### Dependency licenses

`dependency-licenses` reads the `license` entries of `composer.lock` and `package-lock.json` (lockfileVersion 2 and 3). It sends nothing anywhere. Each package gets one verdict:

1. **Denied** (fail, high): a license matches `denied`.
2. **Allowed** (no row): a license matches `allowed`.
3. **Strong copyleft** (warn, medium): GPL, AGPL, SSPL, EUPL, OSL, RPL or Sleepycat. LGPL, MPL, EPL and CDDL are weak copyleft and do not warn.
4. **Known** (no row): one of the common SPDX ids Excubia knows (MIT, BSD, Apache, ISC, LGPL, MPL and about 35 more).
5. **Missing or unknown** (warn, low): everything else, such as no license, `UNLICENSED`, `proprietary`, `SEE LICENSE IN …`, an expression that does not parse, or a rarer SPDX id. Add a valid id you accept to `allowed`.

Expressions follow SPDX: `OR` passes when any option passes, `AND` needs every part, parentheses group, and `WITH` exceptions are read as the license they modify. Composer's `license` array is a choice, like `OR`. A `+`, `-only` or `-or-later` suffix is matched both as written and without the suffix, so `"GPL-2.0"` in `allowed` also covers `GPL-2.0-or-later` and `GPL-2.0+`. Globs use `*` for any characters and `?` for exactly one, case-insensitively, against the whole id; only letters, digits, `. + : -` and the two wildcards are allowed.

The JSON report adds a top-level `licenses` inventory with the counts per license and per ecosystem (see the [README](../../README.md#formats)). To accept one package instead of a license, add an `ignore` rule with `checkId` `dependency-licenses` and the package name as `subject`.

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
| `EXCUBIA_HEALTH_SECRET` | health endpoint | Secret expected in the `oh-dear-health-check-secret` header, at least 32 characters. Without it, or when it is shorter, the route is not registered. |
| `GITHUB_TOKEN` | `composer-stale` | Optional. Raises the GitHub API rate limit for the archived-repository lookup. Sent only to `api.github.com`; never printed, cached or written to a report. |
| `HTTPS_PROXY` | all HTTP requests | Proxy for the data sources and the push, read by Symfony HttpClient. |
| `NO_COLOR` | `table` format | Turns colours off. |

Empty values count as unset.
