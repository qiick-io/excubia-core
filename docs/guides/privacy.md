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

- `GITHUB_TOKEN` is optional, read from the environment only, sent only to the GitHub API as a bearer header, and never printed or written to the cache or the report.
- All HTTP is over TLS with timeouts (connect 5 s, total 15 s) and limited retries; `HTTPS_PROXY` is respected.
- Excubia never writes to the scanned project except the `--output` file, and `vendor/bin/excubia` never executes project code.
- The Artisan commands run inside the booted app. The posture checks read it without writing, except the scheduler heartbeat cache key `excubia:scheduler-heartbeat`, and their findings carry only booleans, counts, ages and names: never `.env` values, DSNs, hostnames, paths or exception messages.
- The response cache stores public API responses only, in the system temporary directory unless `cache.directory` says otherwise.

## Push

`excubia push` / `excubia:push` send the JSON report (`docs/report-schema.json`) to `POST {url}/api/reports`: package names and versions, runtime versions and findings (check id, severity, status, subject, message, fix hint, evidence URL, advisory id), inside an envelope with `protocolVersion`, `batchId` and `sentAt`.

- Nothing is sent until an app URL and a project token are configured.
- HTTPS only; plain HTTP only to `localhost`, `127.0.0.1` and `[::1]`.
- The token is sent as `Authorization: Bearer` and is never printed, cached or written to a report.

## Runtime monitoring

Off until `runtime.enabled` is true. Sends only these aggregates per UTC minute, each with `count`, `errorCount`, `totalMs`, `maxMs` and its `sampleRate` (`docs/runtime-schema.json`):

- requests: route name (or `unnamed`), HTTP method, status class (`2xx`, …);
- slow queries: SQL text without bindings, with quoted string literals replaced by `?`, and the connection name;
- jobs: job class, queue and connection names, `processed` or `failed`;
- scheduled tasks: the description, or `artisan <command>` without the PHP and Artisan binaries, or `Closure`; paths relative to the project (a file name outside it); `finished`, `failed` or `skipped`;
- outgoing HTTP (Laravel's HTTP client): host and status class.

Never sent, and never written to the local buffer: URLs and query strings, request or response bodies, headers, cookies, users, IP addresses, query bindings, exception messages, `.env` values or absolute paths.

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
| Absolute paths | project root removed; other paths reduced to the file name |
| URL credentials | removed |
| URL query strings and fragments | `?[query]` |
| Quoted SQL literals, when the message carries SQL | `?` |
| Database `Host`, `Socket`, `Port`, `Database` details | `[masked]` |
| JWTs | `[jwt]` |
| `Bearer` / `Basic` credentials | `[token]` |
| `password=`, `secret:`, `token=`, `api_key=` and similar values | `[masked]` |
| Emails | `[email]` |
| IPv4 and IPv6 addresses | `[ip]` |
| UUIDs | `[uuid]` |
| Hex strings of 32+ characters, 20+ character strings mixing letters and digits (API keys) | `[token]` |
| Card-like runs of 13–19 digits, any number longer than four digits | `[number]` |

Query exceptions are rebuilt from the driver message, the connection name and the SQL without bindings. Never sent: request payloads, headers, cookies, users, IP addresses, query bindings, `.env` values or absolute paths.

## Health endpoint

Off until `healthEndpoint.enabled` is true and `EXCUBIA_HEALTH_SECRET` is set. It sends nothing by itself; it answers a `GET` that carries the secret, with check ids, check names, statuses, severities, counts and package or runtime names. Finding messages, fix hints and evidence URLs are not included, so no path, hostname, token or `.env` value reaches the response. A wrong or missing secret gets `404`.

## Local files

| Path | Written by | Content |
| --- | --- | --- |
| `--output` file | `check` | The report you asked for |
| System temp `qiick-excubia/` or `cache.directory` | all checks | Public data-source responses |
| `storage/excubia/runtime/` | runtime monitoring | Aggregates waiting to be sent, capped by `runtime.bufferMaxBytes` |
| `storage/excubia/exceptions/` | exception capture | Masked groups waiting to be sent, capped by `exceptions.bufferMaxBytes` |
| `storage/excubia/health.json` | `excubia:check`, `excubia:push` with the endpoint on | The last health result |
| Cache key `excubia:scheduler-heartbeat` | `excubia:heartbeat` | A Unix timestamp |

Report a privacy or security problem as described in [`SECURITY.md`](../../SECURITY.md).
