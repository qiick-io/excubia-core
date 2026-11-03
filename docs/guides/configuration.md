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
    "laravel-failed-jobs": { "highSeverityAfterHours": 24 }
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

## Ignore rules

`ignore` is a list of findings you accept. A rule matches a finding with the same `checkId` and `subject`, and, when `advisoryId` is set, the same advisory. Ignored findings are removed from the report, the exit code and the health endpoint.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `ignore` | list of objects | `[]` | Accepted findings. |
| `ignore[].checkId` | string, required | | Check id, for example `composer-advisories`. |
| `ignore[].subject` | string, required | | The finding subject: a package (`vendor/package`), a runtime (`php`, `laravel/framework`, `node`) or, for the posture checks, `app.debug`, `config-cache`, `route-cache`, `event-cache`, `migrations`, `failed-jobs`, `scheduler` or `maintenance-mode`. |
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

## excubia-app

The target of `excubia push`, `excubia:push` and `excubia:push-runtime`. See the [push guide](./excubia-app.md).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `app` | object | | Your own `excubia-app`. |
| `app.url` | string | — | Base URL of the app, for example `https://excubia.example.com`. HTTPS, or plain HTTP to `localhost`, `127.0.0.1` or `[::1]` only. `EXCUBIA_APP_URL` wins. |
| `app.token` | string | — | Project push token from the app. `EXCUBIA_APP_TOKEN` wins. Prefer the environment variable so the token is not committed. |

## Health endpoint

See the [health endpoint guide](./health-endpoint.md). The secret is not a key: it comes from `EXCUBIA_HEALTH_SECRET` only.

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `healthEndpoint` | object | | Oh Dear-compatible endpoint, Laravel only. |
| `healthEndpoint.enabled` | boolean | `false` | Register the `GET` route (only when `EXCUBIA_HEALTH_SECRET` is set too). |
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
| `EXCUBIA_HEALTH_SECRET` | health endpoint | Secret expected in the `oh-dear-health-check-secret` header. Without it the route is not registered. |
| `GITHUB_TOKEN` | `composer-stale` | Optional. Raises the GitHub API rate limit for the archived-repository lookup. Sent only to `api.github.com`; never printed, cached or written to a report. |
| `HTTPS_PROXY` | all HTTP requests | Proxy for the data sources and the push, read by Symfony HttpClient. |
| `NO_COLOR` | `table` format | Turns colours off. |

Empty values count as unset.
