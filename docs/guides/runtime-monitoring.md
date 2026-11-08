# Runtime monitoring

In a Laravel 12 or 13 app, Excubia can send runtime aggregates to your own `excubia-app`: request, slow query, job, scheduled task and outgoing HTTP timings, and, opt-in per type, memory, queue wait, cache, mail, notification, log level and PHP deprecation counts, N+1 duplicate query detection, Artisan command runs and Laravel AI SDK usage (calls, latency, failures, failovers and tokens per agent, provider and model, and agent tool calls) Laravel Octane workers (restarts, requests, errors and memory growth per worker) Laravel Reverb servers (connections, messages, errors and channels per app) and Laravel Cashier webhooks (received, handled, failed and payment signals per provider and event type). It is off by default. While it is off, the service provider registers no listener and no schedule, and nothing runs.

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
    "deprecations": { "enabled": true },
    "duplicateQueries": { "enabled": true, "threshold": 5 },
    "server": { "enabled": true },
    "commands": { "enabled": true },
    "health": { "enabled": true },
    "ai": { "enabled": true },
    "aiTools": { "enabled": true },
    "octane": { "enabled": true },
    "reverb": { "enabled": true },
    "billing": { "enabled": true }
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

- Laravel events are aggregated in memory per UTC minute: `count` and `errorCount`, plus `totalMs`, `maxMs` and a latency `histogram` for durations, `totalMb` and `maxMb` for memory, or `hits`, `misses`, `writes` and `deletes` for the cache.
- The latency `histogram` (requests, slow queries, jobs, scheduled tasks, outgoing HTTP, queue wait, commands, AI operations and AI tool calls) lets excubia-app show p50, p95 and p99. It is 12 counts, one per bucket with the upper bounds 5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000 and 10000 ms (inclusive), plus one for anything slower; the counts add up to `count`. The bounds are fixed, so rows add up element by element across minutes and batches, and the app interpolates linearly inside a bucket (recipe in `$defs.histogram` of [`docs/runtime-schema.json`](../runtime-schema.json)). A percentile is exact to the bucket: p95 = 180 ms means "between 100 and 250 ms, about 180". Recording a sample is a bucket lookup and one increment, about 0.0002 ms (`vendor/bin/pest --group=benchmark`).
- Scheduled task aggregates carry a `taskId` (#146), sha256 of the task name and its cron expression, the same value as in the `schedule` inventory that `excubia:push` sends, so excubia-app can tell missed, late and failed runs apart ([excubia-app guide](./excubia-app.md#scheduled-tasks)).
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
| `mail` | `MessageSending` / `MessageSent`, settled at the next send, `JobProcessed` / `JobExceptionOccurred` / `JobFailed`, `CommandFinished` and terminate | A `MessageSending` with no `MessageSent` |
| `notifications` | `NotificationSent` / `NotificationFailed` | The notification failed |
| `logLevels` | `MessageLogged`, with the channel from a logging tap (#174) | — |
| `deprecations` | A PHP error handler for `E_DEPRECATED` / `E_USER_DEPRECATED`, chained in front of Laravel's | — |
| `duplicateQueries` | `QueryExecuted`, evaluated at `RequestHandled` or when a job ends (`JobProcessed` / `JobFailed` / `JobExceptionOccurred`) | — |
| `server` | One sample per `excubia:push-runtime` run, on each server | — |
| `commands` | `CommandStarting` / `CommandFinished`, top-level commands only | Non-zero exit code |
| `health` | One sample of the fast Artisan checks per `excubia:push-runtime` run, at most once a minute, on each server | The check failed |
| `ai` | Laravel AI SDK: `PromptingAgent` / `StreamingAgent` → `AgentPrompted` / `AgentStreamed` / `AgentFailed` / `AgentFailedOver`, and the start, done and failed events of images, embeddings, transcriptions, reranking, audio and classification (`Classifying` → `Classified` / `ClassificationFailed`), plus `ProviderFailedOver` | The attempt failed |
| `aiTools` | Laravel AI SDK: `ToolInvoked` / `ToolFailed` | The tool threw |
| `octane` | Laravel Octane: `WorkerStarting`, `RequestReceived` / `RequestTerminated`, `TaskReceived`, `TickReceived`, `WorkerErrorOccurred`, `WorkerStopping` | A worker error |
| `reverb` | Laravel Reverb: `MessageReceived`, `MessageSent`, `ConnectionPruned`, `ChannelCreated`, `ChannelRemoved`, plus a connection sample once a minute | A `pusher:error` sent to an established connection |
| `billingWebhook` | Laravel Cashier and Cashier Paddle: `WebhookReceived`, `WebhookHandled`, and `RequestHandled` for the response status | The request ended with a 5xx before Cashier's handler returned |

Notes on the newer types:

- **Memory** is PHP's peak (`memory_get_peak_usage()`) in MiB; the average is `totalMb / count`. `jobMemory` resets the peak when each job starts, so it reports the job's own peak; a request that runs sync jobs still reports its whole peak. Under Octane the request peak only grows across requests; use `octane` for per-worker memory.
- **Queue wait** needs the push time. With `queueWait` on, Excubia adds an `excubiaQueuedAt` timestamp to every job payload pushed by the app; Horizon's `pushedAt` is used when that key is missing. The job's delay is subtracted, retries are not counted, and clock differences between the pushing server and the worker show up in the wait.
- **Cache** keys are never sent. Only the store name and the prefix before the first `:` or `.` are kept, through the exception message masks; a prefix that still looks like an id, a token or personal data becomes `[masked]`. Use prefixes such as `users:` or `reports.` in your keys to get useful rows.
- **Mail failures** (#175): Laravel has no event for a failed mail, but its mailer fires `MessageSent` right after the transport returns. A `MessageSending` with no `MessageSent` by the end of its scope is counted as one failed mail: the scope ends at the next send in the same process, when the queued job or job attempt ends (`JobProcessed`, `JobExceptionOccurred`, `JobFailed`, so a failed `SendQueuedMailable` or queued mail notification counts once per attempt), when an Artisan command ends, and when the request or process terminates. This works with [exception capture](./exception-capture.md) off and does not depend on it: with capture on, the reported transport exception is not counted again, and a failure your code catches is still counted. Limits: only sends through Laravel's mailer are seen (a Symfony transport used directly is not); a `MessageSending` listener of your own that cancels the send by returning `false` or throws is counted as a failed mail when it runs after Excubia's; a queued listener on the sync driver that runs inside `MessageSending` counts that send as failed and then as sent; a worker killed by a job timeout during the send loses it.
- **Log levels** are counted per level and log channel (#174). Laravel's `MessageLogged` event does not say which channel logged, so Excubia appends a `tap` to each channel in `logging.channels` (your own taps stay and run first) and watches the channels already resolved when it boots. The tap pushes a Monolog processor that only remembers the channel name for the next `MessageLogged`; it returns the record unchanged and never reads the message or the context. A stack reports the stack's name (`stack`), not the channels inside it. Entries from on-demand channels (`Log::build()`, `Log::stack([...])`) and from a `MessageLogged` fired by hand have no `channel`. It costs about 0.0002 ms per log entry (`vendor/bin/pest --group=benchmark`). Excubia never logs, and messages logged while its listener runs are ignored, so the listener cannot loop.
- **Deprecations** feed upgrade readiness in excubia-app: how many deprecations the code really triggers on this PHP version, and where. When the provider boots, Excubia puts an error handler in front of the current one (Laravel's). It counts `E_DEPRECATED` and `E_USER_DEPRECATED` and then always calls the previous handler with the same arguments, so Laravel still writes deprecations to its `deprecations` log channel when you configured one, still turns warnings into exceptions, and Sentry, Flare or any other handler still sees everything. Each row is the source (`app` for a file in your project outside `vendor/`, `vendor/<vendor>/<package>` for a dependency, `unknown` outside the project), the message through the exception message masks and cut to 300 characters, and the PHP version (`8.4`). Never the file path, the line or the stack. A request that raises no deprecation never calls the handler, so it costs nothing (`vendor/bin/pest --group=benchmark`: the difference is run-to-run noise and the handler is called zero times; about 0.01 ms per deprecation raised). Things to know: silenced deprecations (`@trigger_error()`, the Symfony convention) are counted too, as Laravel logs them; for `E_USER_DEPRECATED` the source is the package that calls `trigger_error()`, not your code that called it; deprecations PHP raises while compiling a file (such as an implicitly nullable parameter) appear only when the file is compiled, so with OPcache they are counted once per cache fill; deprecations raised before the provider boots are not seen; and a handler set after Excubia's that does not forward to the previous one hides them from Excubia. The Laravel log channel is not used as the source: `MessageLogged` does not say which channel logged, and Laravel skips that channel in some cases.
- **Duplicate queries** find N+1 problems, as debug bars and APM tools do: the same query shape run many times within one request or job, usually a relation loaded in a loop. Every query of the request or job is grouped by its normalised SQL and connection name: inline literals become `?` (as for slow queries), `IN (?, ?, …)` lists of any length become `IN (…)`, and whitespace is collapsed; bindings are never read. When a shape runs at least `runtime.duplicateQueries.threshold` times (default `5`, at least `2`), it is recorded once for that request or job, under `origin` `request` with the route name (or `unnamed`) or `origin` `job` with the job class. `count` is how many requests or jobs had it; `totalRepeats` adds up the executions over those requests or jobs (the average is `totalRepeats / count`) and `maxRepeats` is the most in a single one. The counts are cleared after each request and each job; a sync job dispatched inside a request is counted on its own. At most 200 distinct shapes are counted per request or job, so memory stays bounded; later shapes are ignored. `sampleRate` is the share of requests and jobs evaluated. Queries in Artisan commands and scheduled tasks are not evaluated. Cost (`vendor/bin/pest --group=benchmark`): about 0.0002 ms per repeated query, since each raw SQL string is normalised once, and about 0.004 ms the first time a query is seen.
- **Commands** show how long manual and deploy-time Artisan commands take and how often they fail (`migrate`, `optimize`, your own `app:*` commands), like the commands view of Nightwatch. Each row is the command name and the exit code class: `0`, `1` (Laravel's usual failure) or `other`; `errorCount` counts the non-zero ones. Arguments and options are never read, so `migrate --force` and `migrate` are one row. Left out:
  - Excubia's own `excubia:*` commands, always;
  - the commands in `runtime.commands.ignore`, names or `*` patterns, by default `schedule:run`, `schedule:work`, `queue:work`, `queue:listen`, `horizon*` and `tinker`: long-running or interactive commands whose duration says nothing, and the queue and the scheduler have their own types. Setting `ignore` replaces that list, so keep the defaults you still want (`"ignore": ["schedule:*", "queue:*", "horizon*", "tinker", "app:watch"]`);
  - commands called by another command (`Artisan::call()` inside a command): only the top-level command of a process counts, and the time of the commands it calls is part of its own;
  - commands run by the scheduler, already counted as `scheduledTask`. A scheduled closure or job that calls a command runs inside `schedule:run`, so it is a nested command. A scheduled `->command()` runs as its own `php artisan …` process: while `schedule:run`, `schedule:work` or `schedule:test` runs, Excubia sets `EXCUBIA_INSIDE_SCHEDULER=1` in its environment, the task processes inherit it, and a process that has it counts no command. It is removed when the scheduler command ends, so a long-lived process that called `schedule:run` keeps counting. A task with `->user()` runs through `sudo`, which clears the environment, so it is counted as both. Do not set the variable yourself.

  The buffer is written when each command finishes, because a long-lived process (Octane, a worker calling `Artisan::call()`) does not terminate after it; for a plain `php artisan` run, the flush when the app terminates then finds nothing left. A command that calls `exit()` fires no `CommandFinished` and is not counted. Laravel fires these events only for commands run through the console kernel (`php artisan`, `Artisan::call()`), not in its own test runs.

### Server metrics

With `runtime.server.enabled`, every `excubia:push-runtime` run (each minute, on every server that runs the scheduler) first takes one sample of the machine and sends it with the batch, as `server` aggregates (1.0.0-beta.2, #149):

| Field | Linux | macOS and others |
| --- | --- | --- |
| `loadAverage1`, `loadAverage5`, `loadAverage15` | `/proc/loadavg` | `sys_getloadavg()` |
| `cpuCores` | `processor` entries of `/proc/cpuinfo` | `null` |
| `cpuLimit` (cores, 1.0.0-beta.2, #173) | the cgroup CPU quota: `cpu.max`, or `cpu.cfs_quota_us / cpu.cfs_period_us` in cgroup v1; `null` without a quota | `null` |
| `memoryUsedMb`, `memoryTotalMb` (MiB) | `/proc/meminfo`: `MemTotal - MemAvailable`; in a container with a memory limit, the cgroup's usage minus inactive page cache, out of the limit | `null` |
| `diskUsedGb`, `diskTotalGb` (GiB) | the disk that holds `storage/` | the same |
| `isContainer` (#173) | `true` when a cgroup memory limit or CPU quota applies | `false` |

Everything is read with PHP, never through a shell; macOS exposes cores and memory only through `sysctl` and `vm_stat`, so they are `null` there. A value that cannot be read is `null`. The values are gauges: when one server has two samples in the same minute, each field keeps the larger one, and `isContainer` is `true` when either is.

**Containers** (Docker, Kubernetes): `/proc` shows the host there, so Excubia also reads the cgroup mounted at `/sys/fs/cgroup`, v2 (`memory.max`, `memory.current`, `memory.stat`, `cpu.max`) before v1 (`memory/memory.limit_in_bytes`, `memory/memory.usage_in_bytes`, `memory/memory.stat`, `cpu/cpu.cfs_quota_us`, `cpu/cpu.cfs_period_us`). A memory limit replaces the host's memory: used is the usage minus the inactive page cache, as `docker stats` shows it, and total is the limit. A CPU quota is sent as `cpuLimit`, quota / period in cores with 2 decimals, possibly fractional (`--cpus=1.5` is `1.5`); `cpuCores` stays the logical core count that `/proc` shows, usually the host's, so compare the load with `cpuLimit` when it is set. `max`, `-1`, cgroup v1's "unlimited" value and a memory limit at or above the machine's memory count as no limit; a container without limits reports the host values and `isContainer` `false`. Only the cgroup at `/sys/fs/cgroup` is read: a host process inside a limited systemd slice reports the host. The cgroup path is never sent.

Each server pushes its own samples, so a server that does not run the scheduler sends none. Name each server so the dashboard shows it readably:

```bash
EXCUBIA_SERVER_NAME=web-1
```

Set `EXCUBIA_SERVER_NAME` in each server's environment (`.env`, Forge, the process manager that runs the scheduler): `excubia.json` is usually the same committed file on every server. The variable wins over the `runtime.server.name` key, which still works for a single server. Both take 1 to 64 letters, digits, `.`, `_` or `-`. An invalid `EXCUBIA_SERVER_NAME` does not stop the push: the server is sent as its hashed id and `excubia:push-runtime` prints a warning on stderr. Without a name the server is the first 12 hex characters of sha256(hostname + sha256(app key)): stable for one machine and app, and not reversible to the hostname. The hostname and the storage path are never sent.

### Health samples

`excubia:push` sends the full report once a day, so excubia-app learns that the database or the queue is down hours late. With `runtime.health.enabled` (1.0.0-beta.3, #188), every `excubia:push-runtime` run, at most once per UTC minute on each server, also runs the fast Artisan checks and sends one `health` aggregate per check, so the app can alert within a minute or two:

```json
{
  "runtime": {
    "enabled": true,
    "health": {
      "enabled": true,
      "checks": ["laravel-database", "laravel-cache", "laravel-queue-backlog"],
      "checkBudgetMs": 3000,
      "totalBudgetMs": 15000
    }
  }
}
```

- `checks` defaults to all nine checks that only probe local services: `laravel-database`, `laravel-cache`, `laravel-storage`, `laravel-disk-space`, `laravel-queue-backlog`, `laravel-queue-workers`, `laravel-scheduler-heartbeat`, `laravel-redis-memory` and `laravel-maintenance-mode`. No other id is allowed, so a sample never calls Packagist, OSV, endoflife.date, GitHub or npm. Their `checks.<id>` settings and your `ignore` rules apply, as in the report.
- Each check runs alone, with only the probes it reads (a `select 1`, a cache write and read, the disk, the queue backlog, the worker and scheduler heartbeats, Redis `INFO memory`, the maintenance file). The sample holds `checkId`, `status` (`pass`, `warn`, `fail` or `skipped`), the most serious `severity`, `findingCount` and the `fingerprints` of its warn and fail findings: the same values as the report's finding `fingerprint`, never a message or a name.
- Budgets: a check starts only while `checkBudgetMs` (default 3000) is still left of `totalBudgetMs` (default 15000), counted from the start of the run; the rest are sent as `skipped`. A check whose probe throws is `skipped` too, and the push always goes on. A running probe cannot be interrupted: a database the probe opens has a 3 s connect timeout, other clients keep their own.
- Two samples of one check, server and minute merge with the worst status (fail, then warn, then pass, then skipped: a skipped sample has no reading and never hides one).
- The server is named as for [server metrics](#server-metrics): `EXCUBIA_SERVER_NAME`, else `runtime.server.name`, else a hashed id.

### Laravel AI SDK

With the [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) (`laravel/ai`) in your app, `runtime.ai` and `runtime.aiTools` (1.0.0-beta.4, #198) show what your AI features cost and how reliable they are, next to health and runtime. Excubia does not depend on the SDK: without `laravel/ai` installed both types register nothing and record nothing, whatever `excubia.json` says. Everything stays in your own `excubia-app`, and prompts never leave your app.

```json
{
  "runtime": {
    "enabled": true,
    "ai": { "enabled": true },
    "aiTools": { "enabled": true }
  }
}
```

- **`ai`** rows are keyed by `operation` (`agent`, `image`, `embeddings`, `transcription`, `reranking`, `audio` or `classification`), `agent` (the agent class, for `agent` rows only), `provider` (the name in `config/ai.php`, such as `openai`) and `model`. For an agent, provider and model come from the response (the model the provider answered with), else from the prompt; a stream's are the prompt's.
- `count` is the provider attempts: completed operations plus failed attempts. `errorCount` is the failed ones, and `failoverCount` the failed ones that moved on to the next provider of a failover list (`AgentFailedOver`, `ProviderFailedOver`). For images, embeddings, transcription, reranking and audio, the SDK fires its failed event once per attempt and `ProviderFailedOver` for every failoverable error, the last provider's included.
- Durations run from the start event to the done or failed event of the same invocation id, with `totalMs`, `maxMs` and the latency histogram; a start Excubia did not see (it fired before boot) counts 0 ms. A stream is counted once, at `AgentStreamed`, with the usage of the whole stream. At most 200 starts are kept waiting for their end, so a stream nobody reads cannot grow memory.
- Usage: `inputTokens`, `outputTokens`, `cacheReadInputTokens`, `cacheWriteInputTokens`, `reasoningTokens`, `imageInputTokens`, `imageOutputTokens`, `audioSeconds` and `searchUnits`, as the SDK reports them. A count the provider does not report is `null`, never 0; rows add up, and a sum is `null` only when every row has `null`. A failed attempt has no usage.
- No cost is computed here: prices change, so `excubia-app` computes cost from the tokens. `inputTokens` includes the cached input; cache reads, cache writes and uncached input (`inputTokens - cacheReadInputTokens - cacheWriteInputTokens`) are billed at different rates, which is why they are separate fields.
- **`aiTools`** rows are keyed by `agent` and `tool` (class names), with `count`, `errorCount` (the tool threw) and the time the SDK measured in the tool's handler. An agent used as a tool, an MCP tool or a provider tool shows as the SDK's wrapper class.
- Read: class names, provider and model names, invocation ids (in memory only, to time an operation) and the usage counts. Never the prompt, the instructions, attachments, messages, the response text, reasoning, tool arguments or results, conversation ids or users, or exception messages.
- Cost (`vendor/bin/pest --group=benchmark`): about 0.02 ms per agent run with one tool call in the collector, and 0.03–0.04 ms end to end through the SDK's fake gateway, against provider calls that take hundreds of milliseconds.

### Laravel Octane

[Laravel Octane](https://laravel.com/docs/octane) keeps each worker alive between requests, so a memory leak or stale state builds up per worker until the worker restarts after `max_requests`. `runtime.octane` (1.0.0-beta.4, #213) shows it per worker type. Excubia does not depend on Octane: without `laravel/octane` installed the type registers nothing and records nothing, whatever `excubia.json` says.

```json
{
  "runtime": {
    "enabled": true,
    "octane": { "enabled": true }
  }
}
```

- Rows are keyed by `server` (the server name or hashed id, as for `server`), `octaneServer` (`swoole`, `roadrunner` or `frankenphp`, from the client Octane binds in the worker, else `octane.server`; `unknown` otherwise) and `workerType` (`task` for a Swoole task worker, which runs the tasks and ticks, else `http`).
- `workerStarts` and `workerStops` count `WorkerStarting` and `WorkerStopping` (`max_requests` reached, a reload or a shutdown; a worker that crashed fires none, so starts well above stops is a crash loop). `requests`, `tasks` and `ticks` count what the workers handled; `errorCount` counts `WorkerErrorOccurred` (an exception that escaped a request, task or tick; the request it ended still counts in `requests`).
- `maxMb` is the most memory in use (`memory_get_usage()`) after a request, read at `RequestTerminated`; `maxGrowthMb` the largest growth of it since the worker's first request. A `maxGrowthMb` that keeps rising from minute to minute is the leak signal.
- `totalRequestsAtStop` and `maxRequestsAtStop` are the requests each stopped worker received since it started (`totalRequestsAtStop / workerStops` is the average). Compare them with `max_requests`, which the `laravel-octane-config` check reports with the server type; it warns in production when `max_requests` is unset or 0.
- The buffer is written when a worker starts, errs or stops, at the end of each request (the request's own row lands with the next one), and at most once a minute from tasks and ticks.
- Read: the Octane client's class, the Swoole server's `taskworker` flag, `octane.server`, `memory_get_usage()` and a request counter. Never URLs, routes, the request, the response, task data, exception messages, worker ids or PIDs.
- Cost (`vendor/bin/pest --group=benchmark`): about 0.01 ms per request and tick in the collector, through Laravel's dispatcher included.

### Laravel Reverb

[Laravel Reverb](https://laravel.com/docs/reverb) is the WebSocket server `php artisan reverb:start` runs. `runtime.reverb` (1.0.0-beta.4, #215) counts what it does per app, and the `laravel-reverb` check tells whether it answers. Excubia does not depend on Reverb: without `laravel/reverb` installed the type registers nothing and records nothing, whatever `excubia.json` says.

```json
{
  "runtime": {
    "enabled": true,
    "reverb": { "enabled": true }
  }
}
```

- Rows are keyed by `server` (the server name or hashed id, as for `server`) and `appId`: the Reverb app id (`REVERB_APP_ID`), or the first 12 hex characters of its sha256 when it is not 1 to 32 characters of `A-Z a-z 0-9 _ -`.
- `messagesReceived` counts `MessageReceived` (client messages Reverb handled, Pusher pings and subscriptions included); `messagesSent` counts `MessageSent` (one per receiving connection for a broadcast, plus protocol frames).
- `connectionsOpened` counts the `pusher:connection_established` frames Reverb sends; `connectionsRejected` the `pusher:error` frames sent to a connection before that (over `max_connections`, or an origin not in `allowed_origins`); `errorCount` the other `pusher:error` frames (rate limit, unauthorized private or presence subscription, invalid message); `connectionsPruned` counts `ConnectionPruned` (no pong in time). Excubia compares only the fixed start of each frame, never its data.
- Reverb fires no event when a client closes its connection, so there is no "closed" count. `maxConnections` instead samples, once a minute, the connections subscribed to at least one channel on this server, as Reverb's own `connections` API and Pulse count them.
- `publicChannelsCreated`, `privateChannelsCreated`, `presenceChannelsCreated` and the matching `…Removed` count `ChannelCreated` and `ChannelRemoved` (the last subscriber left) by name prefix only: `private…` (`private-encrypted-` and `private-cache-` included), `presence…`, else public. The name itself is never kept.
- The Reverb server is one long-lived process: it boots the app once and never terminates between messages. When `reverb:start` starts, Excubia adds a 60 s timer to its event loop (ReactPHP's global loop); each tick takes the connection sample and writes the buffer, the same `storage/excubia/runtime` file the scheduled `excubia:push-runtime` reads. So the scheduler on the same machine (and `storage/` directory) sends Reverb's rows like every other row, within about two minutes; a Reverb server on a machine without the scheduler needs `excubia:push-runtime` scheduled there too. What was recorded after the last tick is lost when the server is killed or stops on a signal; `reverb:restart` ends it normally and flushes.
- Read: the app id of each connection, the first bytes of the frames Reverb sends (three fixed Pusher prefixes), the first characters of channel names and the number of subscribed connections. Never message payloads, event or channel names, socket ids, origins, app keys or secrets.
- The `laravel-reverb` check (Artisan only) connects over TCP, with a 2 s timeout, to the host and port of the default broadcast connection when it uses the `reverb` driver (`REVERB_HOST`, `REVERB_PORT`; 443, or 80 for the `http` scheme, when the port is unset), the address your app broadcasts to. It fails at high when nothing answers, and reports only `isReachable` and `latencyMs`, never the host or port.
- Cost (`vendor/bin/pest --group=benchmark`): about 0.005 ms per message sent or received in the collector, 0.006 ms through Laravel's dispatcher; a broadcast to 1,000 subscribers costs about 6 ms. Lower `sampleRate` for very busy servers.

### Laravel Cashier

[Laravel Cashier](https://laravel.com/docs/billing) (Stripe, `laravel/cashier`) and [Cashier Paddle](https://laravel.com/docs/cashier-paddle) (`laravel/cashier-paddle`) keep subscriptions in sync through the provider's webhooks. `runtime.billing` (1.0.0-beta.4, #217) counts those webhooks, and the `laravel-cashier` check tells whether they can be trusted and reach the app. Excubia depends on neither package: without one installed the type registers nothing and records nothing, whatever `excubia.json` says.

```json
{
  "runtime": {
    "enabled": true,
    "billing": { "enabled": true }
  }
}
```

- Rows are keyed by `provider` (`stripe` or `paddle`) and `eventType`, the provider's event type (Stripe's `type`, Paddle's `event_type`, `alert_name` on Paddle Classic), such as `invoice.payment_failed`. It is kept only when it is 1 to 64 characters of `a-z 0-9 _ .`; anything else becomes `unknown`.
- `count` counts `WebhookReceived`, which both webhook controllers fire before their handler; `handledCount` counts `WebhookHandled`, fired after it returns. A webhook whose request ends with a 5xx before that is `errorCount`: Cashier's handler or one of your `WebhookReceived` listeners threw, and the provider will retry it. The rest, `count - handledCount - errorCount`, are event types Cashier has no handler for (it answers 200): your own listeners may still handle them.
- `totalMs`, `maxMs` and `histogram` time each webhook from `WebhookReceived` to `WebhookHandled`, or to the end of the request.
- Payment signals, 1 per webhook of these event types: `paymentFailedCount` (Stripe `invoice.payment_failed`; Paddle `transaction.payment_failed`, Classic `subscription_payment_failed`), `paymentActionRequiredCount` (Stripe `invoice.payment_action_required`, the 3D Secure confirmation; Paddle `subscription.past_due`, a renewal the customer must pay) and `subscriptionCanceledCount` (Stripe `customer.subscription.deleted`; Paddle `subscription.canceled`, Classic `subscription_cancelled`).
- A webhook rejected by the signature middleware (403) never reaches the controller, so it fires no event and is not counted here; it shows as a 4xx `request` row on the `cashier.webhook` route.
- Read: the event type and the response status. Never anything else of the payload: no amounts, currencies, customer or subscription ids, emails, cards or metadata.
- The `laravel-cashier` check (Artisan only) reports, per installed package, a missing webhook secret (`STRIPE_WEBHOOK_SECRET`, `PADDLE_WEBHOOK_SECRET`; high in production, low elsewhere, because Cashier then skips the signature check and accepts forged webhooks), test keys in production (high: Stripe keys starting `sk_test_`, `rk_test_` or `pk_test_`, Paddle's `PADDLE_SANDBOX` or a sandbox API key or test client-side token) and no route to Cashier's webhook controller or a subclass of it (medium). Booleans only: never a key, a secret or a prefix.
- Cost (`vendor/bin/pest --group=benchmark`): about 0.006 ms per webhook in the collector, 0.007 ms through Laravel's dispatcher.

Envelope: `protocolVersion` `1`, `type` `runtime`, `batchId`, `sentAt` and `runtime`. Schemas: [`docs/runtime-schema.json`](../runtime-schema.json) and `$defs.runtimeEnvelope` in [`docs/push-schema.json`](../push-schema.json).

## What is sent

Route names (`unnamed` for routes without a name), HTTP methods and status classes; slow SQL without bindings, with inline literals (quoted strings, numbers, hex and dollar-quoted literals) replaced by `?`, and the connection name; job class, queue and connection names; scheduled task descriptions (paths made relative, secrets masked), else `artisan <command name>` or the executable's file name, without arguments; the host of outgoing calls; with the newer types, peak memory, queue wait times, cache store names and masked key prefixes, mailable, mailer, notification and channel names, log levels, the source package, masked message and PHP version of deprecations, the normalised SQL, connection, route name or job class and repeat counts of duplicate queries, Artisan command names and exit code classes, Laravel AI SDK operation, agent and tool class names, provider and model names and token counts, Octane server and worker types with worker counts and memory, Reverb app ids with connection, message and channel counts, Cashier webhook providers and event types with webhook and payment signal counts, and the server name or hashed id with its load, CPU core count and container CPU quota, memory and disk sizes, and whether it runs in a container. Never cache keys or values, mail recipients, notifiables, log messages, URLs or query strings, bodies, headers, cookies, users, IP addresses, query bindings, exception messages, the file, line or stack of a deprecation, command arguments or options, AI prompts, instructions, attachments, messages, response text, reasoning, tool arguments or results, conversation ids or users, Octane request or task data, worker ids or PIDs, Reverb message payloads, channel names, socket ids or origins, anything of a Cashier webhook but its event type (amounts, currencies, customer or subscription ids, emails, cards), hostnames, `.env` values or absolute paths. Details: [privacy](./privacy.md#runtime-monitoring).

Name your routes (`->name('orders.show')`) to get useful request rows.

## Turn it off

Set `"enabled": false` (or remove `runtime`). The listeners and the schedule disappear at the next boot. Whatever is still in `storage/excubia/runtime/` stays there and is not sent; delete the directory if you want.
