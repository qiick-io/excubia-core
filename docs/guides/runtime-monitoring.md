# Runtime monitoring

In a Laravel 12 or 13 app, Excubia can send runtime aggregates to your own `excubia-app`: request, slow query, job, scheduled task and outgoing HTTP timings. It is off by default. While it is off, the service provider registers no listener and no schedule, and nothing runs.

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

- Laravel events are aggregated in memory per UTC minute: `count`, `errorCount`, `totalMs` and `maxMs` per route, query, job, task or host.
- The aggregates are written to a local buffer under `storage/excubia/runtime/` when the request or command ends, and after each queued job or scheduled task. Nothing is sent during a request.
- `excubia:push-runtime` claims the buffer into batch files and sends them oldest first to `POST {url}/api/runtime`. A batch is deleted only after a 2xx answer. When the app is down, batches are kept and resent with the same `batchId`, so the app stores each once.
- The buffer is capped by `runtime.bufferMaxBytes` (default 1 MiB). Past the cap the oldest entries are dropped, so an unreachable app never fills the disk.
- Every listener catches its own errors: an error inside Excubia never reaches your app. Measured overhead is about 0.1 ms per request with every type on.

| Type | Laravel event | Error when |
| --- | --- | --- |
| `requests` | `RequestHandled` | Status 5xx |
| `slowQueries` | `QueryExecuted`, at or above `thresholdMs` | — |
| `jobs` | `JobProcessing` / `JobProcessed` / `JobFailed` | The job failed |
| `scheduler` | `ScheduledTaskFinished` / `ScheduledTaskFailed` / `ScheduledTaskSkipped` | The task failed |
| `outgoingHttp` | HTTP client `ResponseReceived` | Status 5xx |

Envelope: `protocolVersion` `1`, `type` `runtime`, `batchId`, `sentAt` and `runtime`. Schemas: [`docs/runtime-schema.json`](../runtime-schema.json) and `$defs.runtimeEnvelope` in [`docs/push-schema.json`](../push-schema.json).

## What is sent

Route names (`unnamed` for routes without a name), HTTP methods and status classes; slow SQL without bindings, with inline literals (quoted strings, numbers, hex and dollar-quoted literals) replaced by `?`, and the connection name; job class, queue and connection names; scheduled task descriptions or `artisan <command>`, with paths made relative and secrets masked; the host of outgoing calls. Never URLs or query strings, bodies, headers, cookies, users, IP addresses, query bindings, exception messages, `.env` values or absolute paths. Details: [privacy](./privacy.md#runtime-monitoring).

Name your routes (`->name('orders.show')`) to get useful request rows.

## Turn it off

Set `"enabled": false` (or remove `runtime`). The listeners and the schedule disappear at the next boot. Whatever is still in `storage/excubia/runtime/` stays there and is not sent; delete the directory if you want.
