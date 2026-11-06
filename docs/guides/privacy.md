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

`excubia check` and the Artisan commands send only package **names and versions**, and runtime versions, to the public APIs of the checks: endoflife.date, Packagist (metadata and security advisories), OSV.dev and the GitHub API. No file contents, `.env` values, source code, paths or hostnames.

- Private packages are never sent: see [below](#private-packages).
- `GITHUB_TOKEN` is optional, read from the environment only, sent only to the GitHub API as a bearer header, and never printed or written to the cache or the report.
- All HTTP is over TLS with timeouts (connect 5 s, total 15 s) and limited retries; `HTTPS_PROXY` is respected.
- Excubia never writes to the scanned project except the `--output` file, and `vendor/bin/excubia` never executes project code.
- The Artisan commands run inside the booted app. The posture checks read it without writing, except the scheduler heartbeat cache key `excubia:scheduler-heartbeat`, and their findings carry only booleans, counts, ages and names: never `.env` values, DSNs, hostnames, paths or exception messages.
- The response cache stores public API responses only, in the system temporary directory unless `cache.directory` says otherwise.

### Private packages

Only packages the lockfile shows as served by the public registries are sent (#108):

| Lockfile | Sent | Not sent |
| --- | --- | --- |
| `composer.lock` | `notification-url` is `https://packagist.org/downloads/` | `path` or `artifact` dist, VCS repositories, Satis, Private Packagist (another `notification-url`), no `notification-url` |
| `package-lock.json` | `resolved` starts with `https://registry.npmjs.org/` | `link: true`, `file:` and VCS dependencies, workspace members (no `resolved`), any other registry |

The lockfile cannot tell a private package on the public npm registry, or one fetched through Packagist, from a public one. Name those in `privacy.skipPackages` with globs such as `"acme/*"` or `"@acme/*"` ([configuration](./configuration.md#privacy)):

```json
{ "privacy": { "skipPackages": ["acme/*", "@acme/*"] } }
```

Each check that would send names (`composer-advisories`, `npm-advisories`, `composer-abandoned`, `composer-stale`, `laravel-next-blocker`) reports the skipped packages in one `skipped` row with subject `private packages`, `skipReason` `not-applicable` and a message such as `2 private packages not sent to Packagist: acme/billing, acme/ui.` The names come from your own lockfile and stay in the local report; with the opt-in push they reach only your own `excubia-app`, and the health endpoint shows the subject without the message. A check with no other finding then reports `skipped`; it never fails and `--strict` ignores it. Private packages are not checked for advisories, abandonment or staleness: watch them yourself. `dependency-licenses` sends nothing, so it inspects private packages too; their findings have no public registry link. It reports the license strings the lockfile declares and drops any with characters SPDX never uses (paths, URLs), so no free text from a lockfile reaches the report.

## Push

`excubia push` / `excubia:push` send the JSON report (`docs/report-schema.json`) to `POST {url}/api/reports`: package names and versions, runtime versions and findings (check id, severity, status, subject, message, fix hint, evidence URL, advisory id), inside an envelope with `protocolVersion`, `batchId` and `sentAt`. Reports and runtime and exception batches also carry the `release` identifier you or your platform set (`release`, `EXCUBIA_RELEASE`, the CI commit variables or, for the standalone CLI, the commit id read from `.git`), and `push --deploy` adds `deployedAt`. No branch name, author or commit message is read or sent.

- Nothing is sent until an app URL and a project token are configured.
- HTTPS only; plain HTTP only to `localhost`, `127.0.0.1` and `[::1]`.
- The token is sent as `Authorization: Bearer` and is never printed, cached or written to a report.

## Runtime monitoring

Off until `runtime.enabled` is true. Sends only these aggregates per UTC minute, each with `count`, `errorCount`, `totalMs`, `maxMs` and its `sampleRate` (`docs/runtime-schema.json`):

- requests: route name (or `unnamed`), HTTP method, status class (`2xx`, …);
- slow queries: SQL text without bindings, with inline literals (single-quoted strings, numbers, hex and dollar-quoted literals) replaced by `?`; double-quoted and backtick-quoted identifiers stay, and the connection name;
- jobs: job class, queue and connection names, `processed` or `failed`;
- scheduled tasks: the description you set (paths relative to the project, secrets such as `token=…` masked as in exception messages), else `artisan <command name>` for Artisan commands or the executable's file name for `exec()` tasks, never their arguments, else `Closure`; `finished`, `failed` or `skipped`;
- outgoing HTTP (Laravel's HTTP client): host and status class.

Added in 1.0.0-beta.2 and off until each one is switched on (each with `count`, `errorCount`, its `sampleRate` and the metrics named here):

- request memory (`requestMemory`): route name (or `unnamed`), HTTP method, peak MB (`totalMb`, `maxMb`);
- job memory (`jobMemory`): job class and queue name, peak MB;
- queue wait (`queueWait`): job class and queue name, wait in ms. To measure it, Excubia adds an `excubiaQueuedAt` timestamp to every job payload it pushes;
- cache (`cache`): store name and the key prefix before the first `:` or `.`, through the exception message masks; a prefix that still holds a digit, `@`, a space or other punctuation, hex-only text or only a mask placeholder is `[masked]`. Counts of hits, misses, writes and deletes. Never the whole key or the value;
- mail (`mail`): mailable class (or the notification class of a mail notification, or `unknown`) and mailer name, sent and failed counts. Never recipients, subjects or bodies;
- notifications (`notifications`): notification class and channel name, sent and failed counts. Never the notifiable or the content;
- log levels (`logLevels`): the level and a count. Never the message or the context.

Never sent, and never written to the local buffer: URLs and query strings, request or response bodies, headers, cookies, users, IP addresses, query bindings, exception messages, whole cache keys or values, mail recipients, notifiables, log messages or context, `.env` values or absolute paths.

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

Report a privacy or security problem as described in [`SECURITY.md`](../../SECURITY.md).
