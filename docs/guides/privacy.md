# Privacy

What leaves the machine, for each feature. This page follows [`docs/SPEC.md`](../SPEC.md) section 7; the threat model and how each rule is enforced are in [`docs/SECURITY-MODEL.md`](../SECURITY-MODEL.md).

There is no telemetry, no analytics and no phone-home to qiick or any third party. Besides the public data sources of the checks, the only destination is your own `excubia-app`, and only for the features you turn on.

| Feature | Default | Sends to | What |
| --- | --- | --- | --- |
| [Checks](#checks) | on | Public data sources | Package names and versions, runtime versions |
| [Push](#push) | off until URL and token are set | Your `excubia-app` | The JSON report |
| [Runtime monitoring](#runtime-monitoring) | off (`runtime.enabled`) | Your `excubia-app` | Aggregates per minute |
| [Exception capture](#exception-capture) | off (`exceptions.enabled`) | Your `excubia-app` | Masked exception groups |
| [Health endpoint](#health-endpoint) | off (`healthEndpoint.enabled`) | Whoever has the secret | Check statuses |

## Checks

`excubia check` and the Artisan commands send only package **names and versions**, and runtime versions, to the public APIs of the checks: endoflife.date, Packagist (metadata and security advisories), OSV.dev, the GitHub API and the npm registry. No file contents, `.env` values, source code, paths or hostnames.

- Private packages are never sent: see [below](#private-packages).
- `GITHUB_TOKEN` is optional, read from the environment only, sent only to the GitHub API as a bearer header, and never printed or written to the cache or the report.
- All HTTP is over TLS with timeouts (connect 5 s, total 15 s) and limited retries; `HTTPS_PROXY` is respected.
- Excubia never writes to the scanned project except the `--output` file, and `vendor/bin/excubia` never executes project code.
- The Artisan commands run inside the booted app. The posture checks read it without writing, except the scheduler heartbeat cache key `excubia:scheduler-heartbeat`, and their findings carry only booleans, counts, ages and names: never `.env` values, DSNs, hostnames, paths or exception messages.
- The infrastructure checks (`laravel-database`, `laravel-cache`, `laravel-storage`, `laravel-disk-space`, `laravel-queue-backlog`) run `select 1`, count pending jobs and measure the disk without writing, except the cache probe key `excubia:cache-probe:<random>`, which is deleted right after it is read. Their findings name the connection, store, directory (`storage`, `bootstrap/cache`) or queue and carry percentages, counts and ages; driver errors are dropped because they can hold a host or a DSN. `php-platform-requirements` names the missing extension, the PHP version and the requiring packages.
- `laravel-database-connections` and `laravel-database-size` (#169) run read-only catalog queries on the default connection (`show status like 'Threads_connected'`, `pg_stat_activity`, `information_schema.tables`, `pg_database_size`, SQLite `dbstat`); on SQLite they read the size of the database file. Their findings carry the connection name, the connection count, megabytes and up to five table names, cut to 64 characters: never the database name, host, DSN or file path.
- `laravel-redis-memory` (#170) sends `INFO memory` on each Redis connection the cache, queue or session use (or `checks.laravel-redis-memory.connections`) and keeps only `used_memory`, `maxmemory` and `maxmemory_policy`. Its findings carry the connection name, megabytes and the policy: never a host, port, password or any other field of the reply; a connection error is dropped because it can carry a host.
- `env-example-sync` reads key names only: `config/*.php` through PHP's tokenizer (the files are never executed), and the names in `.env.example`, whose values are dropped while reading. It never opens `.env`. Its findings carry key names and project-relative config file names (`config/services.php`), never a value.
- `laravel-queue-workers` reads the worker heartbeat keys `excubia:worker-heartbeat:<connection>:<queue>`, which queue workers write to the default cache store (the Unix time only, at most once a minute per queue), and, when Laravel Horizon is installed, Horizon's status. Its findings carry connection, queue and Horizon supervisor names, a status, a process count and minutes: never a hostname (the host prefix of Horizon's supervisor names is dropped), a PID or a job payload.
- `laravel-backups` reads, when spatie/laravel-backup is installed, the backups of each configured destination through the package's own API, without writing. Its findings carry the disk name, the age in hours, the size in MB and the count: never a disk root, bucket, backup path, backup name or credential; a destination error is dropped because it can carry a bucket or a host.
- `php-opcache` and `php-production-ini` never read the CLI that runs Artisan. When the health endpoint is enabled, an authenticated request reads `opcache_get_status(false)` (without the script list) and four `ini_get()` booleans of the web SAPI and stores them under the cache key `excubia:php-runtime:web` in the default cache store, at most once an hour: booleans, two percentages, a script count and the Unix time. Never an ini file path, a script path or an ini value.
- `npm-outdated` (#153) sends one request per public direct dependency of `package.json` to `https://registry.npmjs.org/<name>`: the package name only, never its version or your lockfile. Private packages are not sent.
- The response cache stores public API responses only, in the system temporary directory unless `cache.directory` says otherwise.

### Private packages

Only packages the lockfile shows as served by the public registries are sent (#108):

| Lockfile | Sent | Not sent |
| --- | --- | --- |
| `composer.lock` | `notification-url` is `https://packagist.org/downloads/` | `path` or `artifact` dist, VCS repositories, Satis, Private Packagist (another `notification-url`), no `notification-url` |
| `package-lock.json` | `resolved` is an HTTPS tarball on `registry.npmjs.org` or `registry.yarnpkg.com` | `link: true`, `file:` and VCS dependencies, workspace members (no `resolved`), any other registry |
| `yarn.lock` (classic v1) | `resolved` is an HTTPS tarball on `registry.npmjs.org` or `registry.yarnpkg.com` | `file:` and git dependencies, any other registry |
| `yarn.lock` (Berry v2+) | `npm:` resolution, and `.yarnrc.yml` points neither the package's scope (`npmScopes`) nor the default `npmRegistryServer` at another server | `workspace:`, `file:`, `link:`, `portal:`, `patch:`, git and URL resolutions, scopes on another registry |
| `pnpm-lock.yaml` (6 and 9) | registry entry with a plain `resolution` (or a tarball on the public registry), and `.npmrc` points neither its `@scope:registry` nor `registry` at another server | `file:`, `link:`, directory, git and tarball dependencies, scopes on another registry |

`.npmrc` and `.yarnrc.yml` are read for registry URLs only; credentials ignored. Only the `registry` / `@scope:registry` lines and `npmRegistryServer` / `npmScopes` scope entries are looked at, every other line (tokens, `_auth`, passwords) is dropped right away, and the URLs are never stored, sent or reported.

The lockfile cannot tell a private package on the public npm registry, or one fetched through Packagist, from a public one. Name those in `privacy.skipPackages` with globs such as `"acme/*"` or `"@acme/*"` ([configuration](./configuration.md#privacy)):

```json
{ "privacy": { "skipPackages": ["acme/*", "@acme/*"] } }
```

Each check that would send names (`composer-advisories`, `npm-advisories`, `composer-abandoned`, `composer-stale`, `laravel-next-blocker`, `composer-outdated`, `npm-outdated`) reports the skipped packages in one `skipped` row with subject `private packages`, `skipReason` `not-applicable` and a message such as `2 private packages not sent to Packagist: acme/billing, acme/ui.` The names come from your own lockfile and stay in the local report; with the opt-in push they reach only your own `excubia-app`, and the health endpoint shows the subject without the message. A check with no other finding then reports `skipped`; it never fails and `--strict` ignores it. Private packages are not checked for advisories, abandonment or staleness: watch them yourself. `dependency-licenses` sends nothing, so it inspects private packages too; their findings have no public registry link. It reports the license strings the lockfile declares and drops any with characters SPDX never uses (paths, URLs), so no free text from a lockfile reaches the report.

## Push

`excubia push` / `excubia:push` send the JSON report (`docs/report-schema.json`) to `POST {url}/api/reports`: package names and versions, runtime versions and findings (check id, severity, status, subject, message, fix hint, evidence URL, advisory id), inside an envelope with `protocolVersion`, `batchId` and `sentAt`. Reports and runtime and exception batches also carry the `release` identifier you or your platform set (`release`, `EXCUBIA_RELEASE`, the CI commit variables or, for the standalone CLI, the commit id read from `.git`), and `push --deploy` adds `deployedAt`. No branch name, author or commit message is read or sent.

- Nothing is sent until an app URL and a project token are configured.
- HTTPS only; plain HTTP only to `localhost`, `127.0.0.1` and `[::1]`.
- The token is sent as `Authorization: Bearer` and is never printed, cached or written to a report.

## Runtime monitoring

Off until `runtime.enabled` is true. Sends only these aggregates per UTC minute, each with `count`, `errorCount`, `totalMs`, `maxMs`, a latency `histogram` (counts per fixed duration bucket, no individual timings) and its `sampleRate` (`docs/runtime-schema.json`):

- requests: route name (or `unnamed`), HTTP method, status class (`2xx`, …);
- slow queries: SQL text without bindings, with inline literals (single-quoted strings, numbers, hex and dollar-quoted literals) replaced by `?`; double-quoted and backtick-quoted identifiers stay, and the connection name;
- jobs: job class, queue and connection names, `processed` or `failed`;
- scheduled tasks (with a `taskId`, sha256 of the name and the cron expression, #146): the description you set (paths relative to the project, secrets such as `token=…` masked as in exception messages), else `artisan <command name>` for Artisan commands or the executable's file name for `exec()` tasks, never their arguments, else `Closure`; `finished`, `failed` or `skipped`;
- outgoing HTTP (Laravel's HTTP client): host and status class.

Added in 1.0.0-beta.2 and off until each one is switched on (each with `count`, `errorCount`, its `sampleRate` and the metrics named here):

- request memory (`requestMemory`): route name (or `unnamed`), HTTP method, peak MB (`totalMb`, `maxMb`);
- job memory (`jobMemory`): job class and queue name, peak MB;
- queue wait (`queueWait`): job class and queue name, wait in ms. To measure it, Excubia adds an `excubiaQueuedAt` timestamp to every job payload it pushes;
- cache (`cache`): store name and the key prefix before the first `:` or `.`, through the exception message masks; a prefix that still holds a digit, `@`, a space or other punctuation, hex-only text or only a mask placeholder is `[masked]`. Counts of hits, misses, writes and deletes. Never the whole key or the value;
- mail (`mail`): mailable class (or the notification class of a mail notification, or `unknown`) and mailer name, sent and failed counts. Never recipients, subjects or bodies;
- notifications (`notifications`): notification class and channel name, sent and failed counts. Never the notifiable or the content;
- log levels (`logLevels`): the level, the name of the configured log channel (#174) and a count. Never the message or the context;
- PHP deprecations (`deprecations`): the source (`app`, `vendor/<vendor>/<package>` or `unknown`), the deprecation message through the exception message masks (paths made relative or reduced to the file name, secrets, emails, ids and long numbers masked) and cut to 300 characters, the PHP major and minor version, and a count. Never the file path, the line or the stack;
- duplicate queries (`duplicateQueries`, 1.0.0-beta.3): for a query that runs at least `threshold` times in one request or job, whether it was a `request` or a `job`, the route name (or `unnamed`) or the job class, the SQL text with inline literals replaced by `?`, `IN (…)` for lists and whitespace collapsed, the connection name, and how many times it ran (`totalRepeats`, `maxRepeats`). Never the bindings;
- server metrics (`server`, 1.0.0-beta.3): `EXCUBIA_SERVER_NAME` or `runtime.server.name`, else the first 12 hex characters of sha256(hostname + sha256(app key)), with the load average over 1, 5 and 15 minutes, the CPU core count, memory used and total in MiB, and used and total size in GiB of the disk that holds `storage/`; inside a container (#173) also the CPU quota in cores (`cpuLimit`) and the boolean `isContainer`, with the container's memory. Never the hostname, an IP address, the storage path or the cgroup path;
- Artisan commands (`commands`, 1.0.0-beta.3): the command name, the exit code class (`0`, `1` or `other`) and the duration. Never the arguments or options.

Never sent, and never written to the local buffer: URLs and query strings, request or response bodies, headers, cookies, users, IP addresses, query bindings, exception messages, whole cache keys or values, mail recipients, notifiables, log messages or context, hostnames, `.env` values or absolute paths.

## Exception capture

Off until `exceptions.enabled` is true, independent of runtime monitoring. One `reportable()` callback sees only what the app reports (`dontReport` applies), returns nothing so the app's own reporting is unchanged, and skips the classes in `exceptions.ignore`. Per group (same fingerprint, context and environment) it sends exactly (`docs/exception-schema.json`):

- `class` (anonymous classes as `Parent@anonymous`), `fingerprint`, and the masked `message` (at most 1000 characters);
- `frames`, innermost first, at most 50: `file` relative to the project root (`vendor/<package>/…` for dependencies, the bare file name outside the project), `line`, `function` (`Class::method`, closures without their line), `inApp`. Never arguments or local variables;
- `previous`: the classes of chained exceptions (at most 5), without their messages;
- `context`: `request` and the route name (`unnamed` without one), `job` and the job class, `command` and the Artisan command name without arguments, or `unknown`;
- `environment` (`app.env`), `count`, `firstSeen` and `lastSeen`.

Masking, applied before anything is written to the local buffer, in this order:

| What | Becomes |
| --- | --- |
| Matches of `exceptions.maskPatterns` | `[masked]` |
| Absolute paths (`/…`, `C:\…`, `\\server\share\…`) | project root removed; other paths reduced to the file name |
| URL credentials | removed |
| URL hosts and ports | `[host]` (the scheme and path stay) |
| URL query strings and fragments | `?[query]` |
| Quoted SQL literals, when the message carries SQL | `?` |
| Database `Host`, `Socket`, `Port`, `Database` details | `[masked]` |
| JWTs | `[jwt]` |
| `Bearer` / `Basic` credentials | `[token]` |
| Any other `Authorization` / `Proxy-Authorization` value (`Token`, `Digest`, `AWS4-HMAC-SHA256`…) | `[masked]` |
| Host names after `host:`, `hostname=`, `resolve host` or `getaddrinfo for` | `[host]` |
| `password=`, `secret:`, `token=`, `api_key=` and similar values | `[masked]` |
| Emails | `[email]` |
| IPv4 and IPv6 addresses | `[ip]` |
| UUIDs | `[uuid]` |
| Hex strings of 32+ characters, 20+ character strings mixing letters and digits (API keys) | `[token]` |
| Phone-like numbers (`+` and 8 or more digits with separators), US social security numbers, card-like runs of 13–19 digits, any number longer than four digits | `[number]` |

Query exceptions are rebuilt from the driver message, the connection name and the SQL without bindings. Never sent: request payloads, headers, cookies, users, IP addresses, query bindings, `.env` values or absolute paths.

## Health endpoint

Off until `healthEndpoint.enabled` is true and `EXCUBIA_HEALTH_SECRET` is set to at least 32 characters. It sends nothing by itself; it answers a `GET` that carries the secret, with check ids, check names, statuses, severities, counts and package or runtime names. Finding messages, fix hints and evidence URLs are not included, so no path, hostname, token or `.env` value reaches the response. A wrong or missing secret gets `404`, and the route is throttled (60 requests a minute per client IP).

## Local files

| Path | Written by | Content |
| --- | --- | --- |
| `--output` file | `check` | The report you asked for |
| System temp `qiick-excubia-<uid>/` or `cache.directory` | all checks | Public data-source responses |
| `storage/excubia/runtime/` | runtime monitoring | Aggregates waiting to be sent, capped by `runtime.bufferMaxBytes` |
| `storage/excubia/exceptions/` | exception capture | Masked groups waiting to be sent, capped by `exceptions.bufferMaxBytes` |
| `storage/excubia/health.json` | `excubia:check`, `excubia:push` with the endpoint on | The last health result |
| Cache key `excubia:scheduler-heartbeat` | `excubia:heartbeat` | A Unix timestamp |
| Cache key `excubia:cache-probe:<random>` | `laravel-cache` | A random value, deleted right after it is read back |

Report a privacy or security problem as described in [`SECURITY.md`](../../SECURITY.md).
