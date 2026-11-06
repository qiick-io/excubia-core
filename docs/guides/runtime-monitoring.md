# Runtime monitoring

In a Laravel 12 or 13 app, Excubia can send runtime aggregates to your own `excubia-app`: request, slow query, job, scheduled task and outgoing HTTP timings, and, opt-in per type, memory, queue wait, cache, mail, notification, log level and PHP deprecation counts. It is off by default. While it is off, the service provider registers no listener and no schedule, and nothing runs.

## Prerequisites

- The package installed without `--dev`: `composer require qiick/excubia-core`.
- An `excubia-app` URL and project token, set up as in [Push to excubia-app](./excubia-app.md#2-configure) (`EXCUBIA_APP_URL` / `EXCUBIA_APP_TOKEN` or `app` in `excubia.json`).
- The Laravel scheduler running: `* * * * * php artisan schedule:run` from cron, or `php artisan schedule:work`.

## 1. Turn it on

```json
{
  "app": { "url": "https://excubia.example.com" },
  "runtime": { "enabled": true }
}
```

That is all that is needed. Each type can be switched off or sampled:

```json
{
  "runtime": {
    "enabled": true,
    "bufferMaxBytes": 1048576,
    "requests": { "enabled": true, "sampleRate": 1 },
    "slowQueries": { "enabled": true, "sampleRate": 1, "thresholdMs": 1000 },
    "jobs": { "enabled": true, "sampleRate": 1 },
    "scheduler": { "enabled": true, "sampleRate": 1 },
    "outgoingHttp": { "enabled": true, "sampleRate": 1 }
  }
}
```

The values shown are the defaults. `sampleRate` is the share of events recorded, from 0 to 1 (`0.1` records one event in ten). Every key is in the [configuration reference](./configuration.md#runtime-monitoring).

More types, added in 1.0.0-beta.2, are off by default. Switch on the ones you want, once your `excubia-app` understands them:

```json
{
  "runtime": {
    "enabled": true,
    "requestMemory": { "enabled": true },
    "jobMemory": { "enabled": true },
    "queueWait": { "enabled": true },
    "cache": { "enabled": true, "sampleRate": 0.1 },
    "mail": { "enabled": true },
    "notifications": { "enabled": true },
    "logLevels": { "enabled": true },
    "deprecations": { "enabled": true }
  }
}
```

Cache and log events can be frequent; a `sampleRate` below 1 keeps the buffer small.

The provider reads `excubia.json` at boot. With `php artisan config:cache` that still works (the file is not Laravel config), but restart long-running workers (`queue:restart`, Octane) after changing it.

## 2. Check the schedule

```bash
php artisan schedule:list
```

With `runtime.enabled` true you see `php artisan excubia:push-runtime` every minute, next to `php artisan excubia:heartbeat`.

## 3. Send

The scheduler sends every minute. To send by hand:

```bash
php artisan excubia:push-runtime
```

It prints `Sent N runtime batches to excubia-app.`, `No runtime data to send.`, or the error.

| Exit code | Meaning |
| --- | --- |
| `0` | Sent, nothing to send, or both runtime monitoring and exception capture are off. |
| `2` | Configuration error: invalid `excubia.json`, missing URL or token, insecure URL. |
| `3` | The app rejected the token, answered an error or could not be reached. The data is kept for the next run. |

## How it works

- Laravel events are aggregated in memory per UTC minute: `count` and `errorCount`, plus `totalMs` and `maxMs` for durations, `totalMb` and `maxMb` for memory, or `hits`, `misses`, `writes` and `deletes` for the cache.
- The aggregates are written to a local buffer under `storage/excubia/runtime/` when the request or command ends, and after each queued job or scheduled task. Nothing is sent during a request.
- `excubia:push-runtime` claims the buffer into batch files and sends them oldest first to `POST {url}/api/runtime`. A batch is deleted only after a 2xx answer. When the app is down, batches are kept and resent with the same `batchId`, so the app stores each once.
- The buffer is capped by `runtime.bufferMaxBytes` (default 1 MiB). Past the cap the oldest entries are dropped, so an unreachable app never fills the disk.
- Every listener catches its own errors: an error inside Excubia never reaches your app. The collector costs about 0.15–0.5 ms per request with every type on, cache and log listeners included (`vendor/bin/pest --group=benchmark`).

| Type | Laravel event | Error when |
| --- | --- | --- |
| `requests` | `RequestHandled` | Status 5xx |
| `slowQueries` | `QueryExecuted`, at or above `thresholdMs` | — |
| `jobs` | `JobProcessing` / `JobProcessed` / `JobFailed` | The job failed |
| `scheduler` | `ScheduledTaskFinished` / `ScheduledTaskFailed` / `ScheduledTaskSkipped` | The task failed |
| `outgoingHttp` | HTTP client `ResponseReceived` | Status 5xx |
| `requestMemory` | `RequestHandled`, `memory_get_peak_usage()` | — |
| `jobMemory` | `JobProcessing` (peak reset) / `JobProcessed` / `JobFailed` | — |
| `queueWait` | `JobProcessing`, first attempts only | — |
| `cache` | `CacheHit` / `CacheMissed` / `KeyWritten` / `KeyForgotten` | — |
| `mail` | `MessageSending` / `MessageSent`, and the exception capture hook | A reported mail transport exception |
| `notifications` | `NotificationSent` / `NotificationFailed` | The notification failed |
| `logLevels` | `MessageLogged` | — |
| `deprecations` | A PHP error handler for `E_DEPRECATED` / `E_USER_DEPRECATED`, chained in front of Laravel's | — |

Notes on the newer types:

- **Memory** is PHP's peak (`memory_get_peak_usage()`) in MiB; the average is `totalMb / count`. `jobMemory` resets the peak when each job starts, so it reports the job's own peak; a request that runs sync jobs still reports its whole peak. Under Octane the request peak only grows across requests.
- **Queue wait** needs the push time. With `queueWait` on, Excubia adds an `excubiaQueuedAt` timestamp to every job payload pushed by the app; Horizon's `pushedAt` is used when that key is missing. The job's delay is subtracted, retries are not counted, and clock differences between the pushing server and the worker show up in the wait.
- **Cache** keys are never sent. Only the store name and the prefix before the first `:` or `.` are kept, through the exception message masks; a prefix that still looks like an id, a token or personal data becomes `[masked]`. Use prefixes such as `users:` or `reports.` in your keys to get useful rows.
- **Mail failures**: Laravel has no event for a failed mail. A failure is counted only when [exception capture](./exception-capture.md) is on and sees a reported Symfony Mailer transport exception (also counted when `exceptions.ignore` lists it). A failure your code catches without reporting is not counted, and without exception capture the failed count stays 0.
- **Log levels** are counted per level only: Laravel's `MessageLogged` event does not say which channel logged. Excubia never logs, and messages logged while its listener runs are ignored, so the listener cannot loop.
- **Deprecations** feed upgrade readiness in excubia-app: how many deprecations the code really triggers on this PHP version, and where. When the provider boots, Excubia puts an error handler in front of the current one (Laravel's). It counts `E_DEPRECATED` and `E_USER_DEPRECATED` and then always calls the previous handler with the same arguments, so Laravel still writes deprecations to its `deprecations` log channel when you configured one, still turns warnings into exceptions, and Sentry, Flare or any other handler still sees everything. Each row is the source (`app` for a file in your project outside `vendor/`, `vendor/<vendor>/<package>` for a dependency, `unknown` outside the project), the message through the exception message masks and cut to 300 characters, and the PHP version (`8.4`). Never the file path, the line or the stack. A request that raises no deprecation never calls the handler, so it costs nothing (`vendor/bin/pest --group=benchmark`: the difference is run-to-run noise and the handler is called zero times; about 0.01 ms per deprecation raised). Things to know: silenced deprecations (`@trigger_error()`, the Symfony convention) are counted too, as Laravel logs them; for `E_USER_DEPRECATED` the source is the package that calls `trigger_error()`, not your code that called it; deprecations PHP raises while compiling a file (such as an implicitly nullable parameter) appear only when the file is compiled, so with OPcache they are counted once per cache fill; deprecations raised before the provider boots are not seen; and a handler set after Excubia's that does not forward to the previous one hides them from Excubia. The Laravel log channel is not used as the source: `MessageLogged` does not say which channel logged, and Laravel skips that channel in some cases.

Envelope: `protocolVersion` `1`, `type` `runtime`, `batchId`, `sentAt` and `runtime`. Schemas: [`docs/runtime-schema.json`](../runtime-schema.json) and `$defs.runtimeEnvelope` in [`docs/push-schema.json`](../push-schema.json).

## What is sent

Route names (`unnamed` for routes without a name), HTTP methods and status classes; slow SQL without bindings, with inline literals (quoted strings, numbers, hex and dollar-quoted literals) replaced by `?`, and the connection name; job class, queue and connection names; scheduled task descriptions (paths made relative, secrets masked), else `artisan <command name>` or the executable's file name, without arguments; the host of outgoing calls; with the newer types, peak memory, queue wait times, cache store names and masked key prefixes, mailable, mailer, notification and channel names, log levels, and the source package, masked message and PHP version of deprecations. Never cache keys or values, mail recipients, notifiables, log messages, URLs or query strings, bodies, headers, cookies, users, IP addresses, query bindings, exception messages, the file, line or stack of a deprecation, `.env` values or absolute paths. Details: [privacy](./privacy.md#runtime-monitoring).

Name your routes (`->name('orders.show')`) to get useful request rows.

## Turn it off

Set `"enabled": false` (or remove `runtime`). The listeners and the schedule disappear at the next boot. Whatever is still in `storage/excubia/runtime/` stays there and is not sent; delete the directory if you want.
