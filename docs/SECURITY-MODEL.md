# Excubia — Security model

This document lists every path where data leaves a project that uses `qiick/excubia-core` or is stored by it. For each path it says what is sent or stored, where it goes, which trust boundary it crosses, what protects it, and how to turn it off. It ends with the threats considered and the risks that remain.

It describes `develop` as reviewed for #82 (November 2026). Released `0.1.x` contains only the standalone CLI paths (sections 1 and 2). Report a vulnerability as described in [`SECURITY.md`](../SECURITY.md).

## Principles

- Toward public data sources only package names, versions and runtime product slugs leave the machine (SPEC §7). Never file contents, paths, hostnames, `.env` values or source code.
- The only other destination is the user's own `excubia-app`, through opt-in pushes (SPEC §13). Nothing goes to qiick or third parties. There is no telemetry.
- Every opt-in is off by default: push, runtime monitoring, exception capture and the health endpoint each need explicit configuration.
- Masking happens before data is written to disk, so the local buffer never holds what the app must not receive.
- Monitoring code never throws into the host application, and never sends during a request.

## Trust boundaries

| Boundary | Inside | Outside |
| --- | --- | --- |
| B1 Machine → public data sources | the CLI process | packagist.org, repo.packagist.org, api.github.com, endoflife.date, api.osv.dev, registry.npmjs.org |
| B2 Machine → user's `excubia-app` | the CLI or Artisan process | the app the user configured with `EXCUBIA_APP_URL` |
| B3 Process → local disk | the PHP process | the shared temporary directory (response cache), `storage/excubia/` (buffers, health result) |
| B4 Internet → the app | the Laravel app | anyone who can reach the health endpoint URL |
| B5 Repository → CI | the workflow author | the scanned repository's files, including pull requests from forks (GitHub Action) |

## Data flows

### 1. Public data sources (B1) — `check`, `push`, `excubia:check`, `excubia:push`

| Destination | What is sent | Code |
| --- | --- | --- |
| `https://endoflife.date/api/{php,laravel,nodejs}.json` | the product slug only | `EndOfLifeApiService` |
| `https://packagist.org/api/security-advisories/?packages[]=…` | Composer package names (no versions) in one request | `PackagistApiService::securityAdvisories()` |
| `https://repo.packagist.org/p2/<vendor>/<name>.json` | one Composer package name per request, at most once per run (shared by `composer-abandoned`, `composer-stale`, `laravel-next-blocker` and `composer-outdated`) | `PackagistApiService::versions()` |
| `https://api.github.com/repos/<owner>/<repo>` | owner and repository name taken from the Packagist source URL; `Authorization: Bearer $GITHUB_TOKEN` when set | `GitHubRepositoryApiService` |
| `https://api.osv.dev/v1/querybatch` (POST) | npm package names and versions from `package-lock.json`, `yarn.lock` or `pnpm-lock.yaml`, ecosystem `npm` (public packages only; `.npmrc` and `.yarnrc.yml` are read for registry URLs only, credentials ignored) | `OsvApiService` |
| `https://registry.npmjs.org/<name>` (scoped: `@scope%2Fname`) | one npm package name per request, no version, for each public direct dependency of `package.json` (`npm-outdated`, #153) | `NpmRegistryService` |

Every request carries `User-Agent: qiick-excubia` and `Accept: application/json`; the npm registry request asks for `application/vnd.npm.install-v1+json` (abbreviated metadata) instead.

**Controls.** `ai` and `aiTools` listen to the Laravel AI SDK events only when `laravel/ai` is installed; they read the class of the agent and the tool, the provider and model names, the invocation id (in memory, to time an operation; never written) and the usage object's counts, never the prompt, messages, response, tool arguments or results, or the exception, and keep at most 200 pending start times. `octane` listens to Laravel Octane's worker events only when `laravel/octane` is installed; it reads the bound Octane client's class, the Swoole server's `taskworker` flag, `octane.server` and `memory_get_usage()`, never the request, the response, the task data, the exception, the worker id or the PID.
- Private packages are never sent (#108, SPEC §7): a Composer package is sent only when its `composer.lock` entry has Packagist's `notification-url` (`https://packagist.org/downloads/`) and no `path` or `artifact` dist; an npm package only when `resolved` is on `https://registry.npmjs.org/` and it is not a link. `privacy.skipPackages` globs exclude more names. `ExcubiaConfigurationData::publicPackages()` filters every check before a request is built; the GitHub lookup uses the source URL of the Packagist metadata, so it never runs for a private package. The skipped names appear only in the local report as a `skipped` row (`private packages`).
- Names are validated before a URL is built: Composer names must match `vendor/name` in lower case, GitHub owners and names must be `[A-Za-z0-9_.-]+` and not `.` or `..`, npm names must not contain `..` or a newline, and the npm registry only gets names of the form `[@scope/]name` in URL-safe characters (at most 214), with the `/` of a scope encoded. Anything else is skipped, not sent.
- `HttpClientContract` is the only HTTP boundary. `SymfonyHttpClientService` refuses any URL that is not HTTPS (plain HTTP only to `localhost`, `127.0.0.1`, `[::1]`), uses TLS peer verification (Symfony default), a 5 s idle timeout, a 15 s total, two retries with backoff, and honours `HTTPS_PROXY`.
- `GITHUB_TOKEN` is read from the environment at call time, sent only as a header to `api.github.com`, never part of a URL, cache key, cache file, message or report. On a redirect Symfony keeps `Authorization` only for the same scheme and host.
- The `-v` request list prints the method and URL only, without headers and without URL credentials.
- Error messages name the data source and the HTTP status, never the response body.

**Turn it off.** `--offline` (read the cache, send nothing), `--only` / `--skip`, or `checks.<id>.enabled: false` in `excubia.json`. Unset `GITHUB_TOKEN` to send no token.

### 2. Response cache (B3)

| | |
| --- | --- |
| Location | `cache.directory` from `excubia.json`, else `<sys_get_temp_dir()>/qiick-excubia-<uid>`. Never the scanned project unless the user points it there. |
| Contents | one `<sha256 of the request identity>.json` per response: `storedAt` and the decoded public response body. No headers, no token, no project data. |
| Permissions | the default directory is created `0700`; files follow the umask. |
| Lifetime | `cache.ttlHours` (default 24). `excubia cache:clear` deletes only files named like cache entries. |

**Controls.** The default directory is in a location other local users can write to, so it is trusted only when it is a real directory (not a symlink), owned by the current user and not writable by group or others; it is checked again right after `mkdir`. Otherwise the cache is skipped for that run (the checks still run online). This stops another local user from planting responses that hide advisories, or from pointing the directory at the victim's files so that `cache:clear` deletes them. A directory configured in `excubia.json` is the user's choice and is used as is.

**Turn it off.** `--no-cache` (neither read nor written), `excubia cache:clear`.

### 3. Report push (B2) — `push`, `excubia:push`

| | |
| --- | --- |
| Destination | `POST {app.url}/api/reports` on the user's own `excubia-app` |
| What is sent | the envelope of `docs/push-schema.json`: `protocolVersion`, a random `batchId`, `sentAt`, and the JSON report (check ids, statuses, package names, versions, finding messages, fix hints, evidence URLs, upgrade plan). Posture checks add only booleans, counts, ages, cache names and migration names. `excubia:push` adds the schedule inventory (#146): task names as in the runtime aggregates (no arguments), cron expressions, timezone names, flags and environment names. |
| Credentials | `Authorization: Bearer <token>` from `EXCUBIA_APP_TOKEN` or `app.token`. A token in `excubia.json` gets a warning on stderr on every `check`, `push` and `push-runtime` run. |

**Controls.**
- HTTPS only, plain HTTP only to loopback hosts. A non-HTTPS app URL is a configuration error (exit 2) before anything is sent.
- POST requests do not follow redirects. A `3xx` from the app is reported as an error, so a `307`/`308` cannot make the client resend the envelope to another host or over plain HTTP after the HTTPS check.
- The token is a `#[\SensitiveParameter]`, never in a URL, payload, message, cache or report. URL credentials in `app.url` are removed from every message and from the `-v` request list.
- Retries (transport errors and retryable statuses such as `429` or `503`) reuse the same `batchId`, so the app stores a retried batch once.
- `--strict` sends nothing when a data source failed, so the app never stores an incomplete report as complete.

**Turn it off.** Do not configure `app.url` / `EXCUBIA_APP_URL`; the push commands then exit 2 and send nothing. Nothing is pushed by `check`.

### 4. Runtime monitoring (B3, then B2) — `runtime.enabled`

| | |
| --- | --- |
| Collected | per UTC minute: requests (route name or `unnamed`, method, status class); slow queries (SQL without bindings, inline string, numeric, hex and dollar-quoted literals as `?`, connection name); jobs (class, queue, connection, outcome); scheduled tasks (description with paths relative and secrets masked, else `artisan <command name>` or the executable's file name without arguments, else `Closure`; outcome); outgoing HTTP (host, status class). Each with `count`, `errorCount`, `totalMs`, `maxMs`, `sampleRate`. Opt-in per type since 1.0.0-beta.2 (#132): request and job peak memory (route name and method, or job class and queue; `totalMb`, `maxMb`), queue wait (job class, queue, ms), cache (store, masked key prefix; hits, misses, writes, deletes), mail (mailable or mail notification class, mailer; sent and failed), notifications (class, channel; sent and failed), log levels (level, count). Opt-in since 1.0.0-beta.2 (#133): PHP deprecations (source `app`, `vendor/<vendor>/<package>` or `unknown`; message through the exception message masks, at most 300 characters; PHP minor version; count). Opt-in since 1.0.0-beta.2 (#148): duplicate queries (origin `request` or `job`, route name or job class, SQL without bindings with inline literals as `?`, `IN` lists as `IN (…)` and whitespace collapsed, connection name; `totalRepeats`, `maxRepeats`). Opt-in since 1.0.0-beta.2 (#149): server samples, one per `excubia:push-runtime` run (`runtime.server.name` or a 12-character sha256 id of hostname + hashed app key; load averages, CPU cores, memory and disk sizes, read from `/proc` and `disk_total_space()` with PHP, no shell). Opt-in since 1.0.0-beta.2 (#152): Artisan commands (command name, exit code class `0`, `1` or `other`; ms). Opt-in since 1.0.0-beta.3 (#188): health samples, at most one per server and minute (check id, status, most serious severity, warn and fail finding count and their sha256 fingerprints; no message, subject or detail; local probes only, no data source). Opt-in since 1.0.0-beta.4 (#198), only with `laravel/ai` installed: Laravel AI SDK operations (operation, agent class, provider and model name; attempts, failures, failovers, ms, token, audio second and search unit counts) and agent tool calls (agent and tool class; ms). Opt-in since 1.0.0-beta.4 (#213), only with `laravel/octane` installed: Octane workers (server id, Octane server, worker type; worker starts and stops, requests, tasks, ticks, worker errors, memory after a request and its growth, requests at stop). |
| Never collected | URLs, query strings, bodies, headers, cookies, users, IP addresses, bindings, exception messages, whole cache keys or values, mail recipients, subjects or bodies, notifiables, log messages or context, the file, line or stack of a deprecation, Artisan command arguments or options, AI prompts, instructions, attachments, messages, response text, reasoning, tool arguments or results, conversation ids or users, AI exception messages, Octane request or task data, worker ids or PIDs, hostnames, `.env` values, absolute paths. |
| Stored | `storage/excubia/runtime/buffer.ndjson` and claimed `batch-<sequence>-<batchId>.ndjson` files, capped by `runtime.bufferMaxBytes` (default 1 MiB, oldest lines dropped first). Directory created `0775`, files follow the umask, like the rest of Laravel's `storage/`. |
| Sent | `excubia:push-runtime` (scheduled every minute) → `POST {app.url}/api/runtime`, same transport and token as the report push. A batch is deleted only after a 2xx. |

**Controls.** Every write holds an exclusive `flock` on `.lock`. Lines are written with `json_encode`, which escapes newlines, so a route or task name cannot inject an extra NDJSON line; lines that do not decode are skipped. Batch files are found by a strict name pattern and their paths are built from the buffer directory only. All collection runs in a `try`/`catch` that swallows errors, and in-memory aggregates are flushed at most 500 at a time. The cache key prefix goes through the exception message masks and is replaced by `[masked]` unless only letters, `_`, `-`, `/` and in-word mask placeholders remain, so ids, emails and tokens in keys do not leave the app. The log level listener never reads the message or context, Excubia itself never logs, and a message logged while the listener runs is ignored, so it cannot loop. `queueWait` adds one timestamp key (`excubiaQueuedAt`) to job payloads, nothing else. `duplicateQueries` reads only the SQL text and the connection name of each query, never the bindings, and keeps at most 200 shapes (each at most 2000 characters) per request or job in memory, cleared after each one. `commands` reads only the command name and exit code of `CommandStarting` / `CommandFinished`, never the input, and sets one environment variable (`EXCUBIA_INSIDE_SCHEDULER=1`) while a scheduler command runs, removed when it ends. `deprecations` chains one PHP error handler that records only `E_DEPRECATED` and `E_USER_DEPRECATED` and always returns the previous handler's result (or `false` without one), so the host's error handling is unchanged; its recording runs in the same `try`/`catch` and ignores deprecations raised while it records.

**Turn it off.** `runtime.enabled: false` (default) registers no listener and no schedule. Each type can be switched off or sampled (`runtime.<type>.enabled`, `sampleRate`).

### 5. Exception capture (B3, then B2) — `exceptions.enabled`

| | |
| --- | --- |
| Collected | per group: class (anonymous classes as `Parent@anonymous`), fingerprint, masked message (≤ 1000 characters), up to 50 frames (relative file, line, `Class::method`, `inApp`; never arguments or locals), up to 5 previous classes (no messages), context (route name, job class, or Artisan command name without arguments), `app.env`, count, first and last seen. |
| Stored | `storage/excubia/exceptions/`, same buffer format, lock and cap (`exceptions.bufferMaxBytes`) as runtime data. |
| Sent | the same `excubia:push-runtime` run → `POST {app.url}/api/exceptions`. |

**Masking (D17)**, `ExceptionPayloadService::mask()`, before anything is buffered: user `exceptions.maskPatterns` first, then the project root and other absolute paths (`/…`, `C:\…`, `\\server\share\…`), URL credentials, hosts (`[host]`) and query strings, quoted SQL literals (when the message carries SQL), database `Host` / `Socket` / `Port` / `Database`, JWTs, `Bearer` / `Basic` credentials, any other `Authorization` / `Proxy-Authorization` value, host names after `host:`, `hostname=`, `resolve host` and `getaddrinfo for`, `key=value`, `key: value` and `'key' => 'value'` secrets (keys containing `pass`, `pwd`, `secret`, `token`, `api_key`, `access_key`, `private_key`, `auth`, `signature`, `credential`, `cookie`, `session`, `dsn`; quoted values masked whole), emails, IPv4 and IPv6 addresses, UUIDs, long hex strings and API-key-like strings, phone-like (`+` and 8 or more digits), US social security, card-like and long numbers. Query exceptions are rebuilt from the driver message, the connection name and the SQL without bindings. A regular expression that fails (for example on the PCRE backtrack limit) replaces the whole message with `[masked]`, so masking fails closed.

**Turn it off.** `exceptions.enabled: false` (default) attaches nothing to the exception handler. `exceptions.ignore` skips classes; `exceptions.maskPatterns` adds project-specific masks.

### 6. Health endpoint (B4) — `healthEndpoint.enabled`

| | |
| --- | --- |
| Served | `GET /<healthEndpoint.path>`: the Oh Dear JSON stored by the last `excubia:check` / `excubia:push` (check names, labels, statuses, finding counts, highest severity). |
| Stored | `storage/excubia/health.json`, written atomically (temporary file and rename). |
| Credentials | header `oh-dear-health-check-secret` compared with `EXCUBIA_HEALTH_SECRET` (environment only) using `hash_equals`. |

**Controls.** Without a secret, or with one shorter than 32 characters, the route is not registered; `excubia:check` and `excubia:push` warn about a short secret. The route is throttled with Laravel's `ThrottleRequests` (60 requests a minute per client IP), so guessing is slow. A missing, empty or wrong secret gets the app's normal `404`, so the route cannot be told apart from a missing page. The request never runs checks and reads one fixed file. Responses carry `Cache-Control: no-store, private`. The route has no session or CSRF middleware, so it sets no cookie.

**Turn it off.** `healthEndpoint.enabled: false` (default), or unset `EXCUBIA_HEALTH_SECRET`. Rebuild `route:cache` after a change.

### 7. Reports written by the user (local output)

`--format=json|markdown|sarif` and `--output` write the report the user asked for. The JSON report has no paths or hostnames. SARIF locations are manifest paths relative to the scanned project (`composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `config/app.php`…), never absolute. Markdown and table output contain finding messages built from package names that passed the validation above and from public advisory data.

### 8. GitHub Action (B5) — `action.yml`

- Inputs reach the shell only through `env:`; no `${{ }}` expression is interpolated inside a `run:` script, so an input cannot inject shell code.
- Third-party actions are pinned to full commit SHAs: `shivammathur/setup-php` (2.40.0) and `github/codeql-action/upload-sarif` (v4.39.2); the repository's workflows pin `actions/checkout` (v7.0.1). Dependabot updates them weekly.
- Excubia is installed from the action's own checkout in `$RUNNER_TEMP`, outside the scanned project, with the dependency versions of the committed `.github/action-install/composer.lock` (resolved for PHP 8.2, kept in sync with `composer.json` by `tests/Feature/ActionInstallLockTest.php`) and `--no-plugins --no-scripts`, so a SHA-pinned action runs exactly the reviewed code. The scanned project's code, dependencies and Composer scripts are never installed or executed, so a hostile pull request can only change the data Excubia reads.
- Composite actions cannot declare permissions. The README example grants `contents: read` and `security-events: write` (the latter only for the SARIF upload).
- On `pull_request` from a fork the token is read-only and secrets are absent; the SARIF upload is skipped there. `github-token` is optional and only raises the GitHub API rate limit.

**Turn it off.** `upload-sarif: false` skips the upload; omit `github-token` to send no token.

## Threats considered (STRIDE-lite)

| Threat | Path | Mitigation | Residual |
| --- | --- | --- | --- |
| **Spoofing** a data source to hide advisories | 1 | HTTPS with peer verification; no plain HTTP except loopback | A compromised public source is trusted (by design) |
| **Spoofing** responses through a planted cache | 2 | per-user default directory, owner/mode/symlink check, `--no-cache` | A configured shared `cache.directory` is the user's responsibility |
| **Spoofing** the app to collect pushes | 3–5 | HTTPS only; no redirects on POST; token only to the configured origin | Whoever controls `EXCUBIA_APP_URL` receives the data |
| **Spoofing** a project toward the app (forged pushes) | 3–5 | Bearer token per project; warning when `app.token` is set in `excubia.json` (#110) | A token committed despite the warning; rotate it in `excubia-app` |
| **Tampering** with buffered data | 4, 5 | `flock`, JSON-encoded lines, strict batch names, app-side additivity | Anyone who can write `storage/` can alter data before it is sent (same as the app's logs) |
| **Tampering** in CI through a fork PR | 8 | project code never runs; inputs via env; SHA-pinned actions | Excubia's own dependencies are locked and installed without plugins or scripts (#111); a compromised release already in the lock is trusted until the lock is refreshed |
| **Repudiation** | 3–5 | `batchId`, `sentAt`, the app stores per project token | No signing of payloads (not needed for the user's own app) |
| **Information disclosure**: secrets in exception messages | 5 | masking before buffering, fail-closed regexes, user patterns; hosts, Windows and UNC paths and every `Authorization` scheme masked (#104) | Host names in free text without a `host` keyword or URL, and secrets in forms no mask recognises; add `exceptions.maskPatterns` |
| **Information disclosure**: values in SQL | 4, 5 | bindings never read; inline string, numeric, hex and dollar-quoted literals masked (#106) | MySQL double-quoted strings stay, as they cannot be told apart from PostgreSQL / SQLite identifiers |
| **Information disclosure**: secrets in scheduled task commands | 4 | arguments dropped: only `artisan <command name>` or the executable's file name (#109), in the runtime aggregates and the report `schedule` inventory alike (#146, one `ScheduledTaskIdentityService`); descriptions masked | A secret the developer writes into a task description in a form no mask recognises |
| **Information disclosure**: private package names to public sources | 1 | names only, no versions to Packagist; packages the lockfile does not show as served by Packagist or the public npm registry are never sent, nor names matching `privacy.skipPackages` (#108) | A private package the lockfile cannot tell apart (a private package on the public npm registry, or one served through Packagist) is sent unless `privacy.skipPackages` names it |
| **Information disclosure**: registry credentials in `.npmrc` / `.yarnrc.yml` | 1, 2 | the project `.npmrc` (pnpm) and `.yarnrc.yml` (Yarn Berry) are read for registry URLs only; credentials ignored. Each line is matched against `registry=` / `@scope:registry=` or `npmRegistryServer` / `npmScopes.<scope>` and every other line (`_authToken`, `_auth`, `_password`, `npmAuthToken`, `npmAuthIdent`, …) is dropped on the spot, never kept in any object; the URLs are only compared with the public registry, never stored, sent or reported (#21) | — |
| **Information disclosure**: token or credentials in logs | 1, 3 | headers never logged, URL credentials removed, no response bodies in messages | — |
| **Information disclosure**: health result | 6 | secret of at least 32 characters, 404 on mismatch, rate limit, `no-store` (#107) | A long secret that leaks (logs, monitor configuration) gives read access until it is rotated |
| **Information disclosure**: release identifier | 1, 3–5 | only the value the user or the platform sets (`release`, `EXCUBIA_RELEASE`, CI commit variables) or, for the standalone CLI, the commit id read from `.git/HEAD` and its ref file without running `git`; branch names, authors and messages are never read into the report; values outside 100 characters of `[A-Za-z0-9._+/@-]` are dropped (#129) | A release string the user sets to something sensitive is sent to their own app as written |
| **Tampering** through a crafted `.git` in a scanned project | 2 | only `HEAD`, one ref under `refs/` (no `..`), `packed-refs` and the `gitdir:` / `commondir` pointers are read; only a 40- or 64-character hex id is accepted; nothing is executed (#129) | A pointer can make Excubia read the first line of a file elsewhere, but only a commit-id-shaped value is ever reported |
| **Information disclosure**: hosts of outgoing calls | 4 | host only, no URL | Internal hostnames reach the user's own app (by design) |
| **Denial of service** of the host app | 4, 5 | never sends during a request, caps on buffer size, pending aggregates and groups, errors swallowed | A full buffer drops the oldest data |
| **Denial of service** through huge messages | 5 | regex work is linear in practice (5 MB message ≈ 20 ms); failures mask the whole message | — |
| **Elevation of privilege** | 2, 8 | no code from the scanned project is executed; `cache:clear` deletes only cache-named files | — |

## Multi-tenant and multi-host deployments

- Buffers and the health result live in the app's `storage/` directory. All tenants of one Laravel installation share one buffer, so runtime aggregates and exception groups mix tenants; tenant data reaches the app only if it is in a route name, job or task name, or survives masking in an exception message. Use `exceptions.maskPatterns` for tenant identifiers.
- The lock is a local `flock`. Several hosts writing one buffer on a network file system are not supported; give each host its own `storage/` (the app adds aggregates from many batches).
- `storage/excubia/` is created with the same permissions as the rest of `storage/`. Anyone who can read `storage/logs` can read the buffers; protect `storage/` as you already do.

## Dependencies

`composer audit` reports no advisories for `composer.lock` (checked for #82, November 2026). Runtime dependencies are `symfony/console`, `symfony/http-client` and `composer/semver`; `illuminate/*` is only suggested. CI runs Excubia on itself (dogfood), and Dependabot watches Composer and GitHub Actions weekly.

## Residual risks and follow-ups

Every finding of the #82 review is fixed: #104, #106, #107, #108, #109, #110 and #111. The residual risks left are listed per row in the threat table above.

No finding of severity high or critical is open.
