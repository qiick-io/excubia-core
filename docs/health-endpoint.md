# Excubia — Health endpoint

An opt-in HTTP endpoint that serves the latest Excubia result in the [Oh Dear application health](https://ohdear.app/docs/features/application-health-monitoring) JSON format, the same format [spatie/laravel-health](https://github.com/spatie/laravel-health) uses. External monitors can poll it. It complements `excubia push` (SPEC §13, D18) and is not the channel to `excubia-app`.

The response shape, the header, the path default and the config keys below are a public contract ([`docs/CONTRACTS.md`](./CONTRACTS.md), `docs/VERSIONING.md`), frozen with the other contracts at 1.0.0-beta (#76). The wording of `notificationMessage` and `shortSummary` is not part of it.

## Configuration

The endpoint is registered by `Qiick\Excubia\Laravel\ExcubiaServiceProvider`, so it exists only in Laravel apps. It is off by default. Setup, scheduling and Oh Dear configuration are in the [health endpoint guide](guides/health-endpoint.md).

| Key | Type | Default | Meaning |
| --- | --- | --- | --- |
| `healthEndpoint.enabled` | boolean | `false` | Register the `GET` route. |
| `healthEndpoint.path` | string | `excubia/health` | URL path of the route. Leading and trailing `/` are ignored. Letters, digits, `.`, `_`, `~`, `-` and `/` only. |
| `EXCUBIA_HEALTH_SECRET` (environment) | string | — | Expected value of the request header, at least 32 characters. Read from the process environment only. Without it, or when it is shorter, the route is not registered (`excubia:check` and `excubia:push` warn about a short secret on stderr). |

The endpoint serves what the last `php artisan excubia:check` or `php artisan excubia:push` stored; it never runs checks in the request. `bin/excubia` does not store a result.

## Request

```
GET /excubia/health
oh-dear-health-check-secret: <EXCUBIA_HEALTH_SECRET>
```

The header is compared with `hash_equals`. A missing, empty or wrong secret gets the app's normal `404`, so the route is indistinguishable from a missing page. The route has no middleware group (no session, no CSRF). It is throttled with Laravel's `Illuminate\Routing\Middleware\ThrottleRequests` at 60 requests a minute per client IP; more get `429`.

## Response

`200`, `Content-Type: application/json`, `Cache-Control: no-store, private`.

```json
{
  "finishedAt": 1762164000,
  "checkResults": [
    {
      "name": "php-eol",
      "label": "PHP end of life",
      "status": "failed",
      "notificationMessage": "1 finding (highest severity: critical): php.",
      "shortSummary": "1 finding",
      "meta": { "findingCount": 1, "highestSeverity": "critical" }
    },
    {
      "name": "composer-abandoned",
      "label": "Abandoned packages",
      "status": "ok",
      "notificationMessage": "",
      "shortSummary": "OK",
      "meta": { "findingCount": 0 }
    }
  ]
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `finishedAt` | integer | Unix timestamp (UTC) when the stored run finished. For the "not run yet" answer, the time of the request. |
| `checkResults[]` | list | One entry per check of the stored run, in report order (one per check id, or the single `excubia-run` entry). |
| `name` | string | Check id (`php-eol`, …), or `excubia-run`. |
| `label` | string | Check name as in the report. |
| `status` | string | `ok`, `warning`, `failed`, `crashed` or `skipped` (mapping below). |
| `notificationMessage` | string | Empty for `ok`. For `warning`/`failed`: count, highest severity and up to 5 subjects (package or runtime names), then `and N more`. For `skipped`: the skip reason. |
| `shortSummary` | string | `OK`, `Skipped`, `N finding(s)`, `Crashed` or `Not run yet`. |
| `meta` | object | `findingCount` (warn and fail findings) and, when it is above 0, `highestSeverity`. `[]` on the `excubia-run` entry. |

### Status mapping

| Excubia check status | Oh Dear status |
| --- | --- |
| `pass` | `ok` |
| `warn` | `warning` |
| `fail` | `failed` |
| `skipped` | `skipped` |
| the run produced no report | `crashed` (single `excubia-run` entry) |

Findings removed by `ignore` rules are not counted, as in the report. `--fail-on` does not change the status: a `warn` finding is `warning` whatever the threshold.

The skipped message tells the reason apart: `a data source did not respond` (`data-source-failure`), `no cached data for an offline run` (`offline`), `the check does not apply to this project` (`not-applicable`).

### Crashed

When `excubia:check` or `excubia:push` throws (a check threw) or ends without a report (invalid `excubia.json`, missing `composer.lock`), the stored result is replaced by:

```json
{
  "finishedAt": 1762164000,
  "checkResults": [
    {
      "name": "excubia-run",
      "label": "Excubia run",
      "status": "crashed",
      "notificationMessage": "The last Excubia run did not finish. Run php artisan excubia:check to see the error.",
      "shortSummary": "Crashed",
      "meta": []
    }
  ]
}
```

A check that throws stops the whole run (the report has no per-check crash state), so `crashed` is reported for the run, not for one check. The exception message is not copied: it can hold a path.

### Not run yet

Before the first stored run, the answer is `200` with one `skipped` entry:

```json
{
  "finishedAt": 1762164000,
  "checkResults": [
    {
      "name": "excubia-run",
      "label": "Excubia run",
      "status": "skipped",
      "notificationMessage": "No Excubia result is stored yet: the scheduler has not run excubia:check or excubia:push.",
      "shortSummary": "Not run yet",
      "meta": []
    }
  ]
}
```

## Storage and privacy

The result is one file, `storage/excubia/health.json` (`storage_path('excubia/health.json')`), written atomically after each Artisan run while the endpoint is enabled. It is the app's own runtime storage, not a file of the scanned project: SPEC §7 ("never writes to the scanned project except `--output`") is about `bin/excubia` scanning a project, and `bin/excubia` never writes it. The response cache directory is not used because it defaults to the shared system temp directory, which is not durable and is shared across projects.

The payload holds check ids, check names, statuses, severities, counts and finding subjects: package or runtime names, and for the infrastructure checks the connection, store or queue name, `storage`, `bootstrap/cache`, `disk`, `php` or an `ext-*` name. Finding messages, fix hints and evidence URLs are not copied, so no absolute path, hostname, token or `.env` value reaches the response.

An authenticated request also refreshes, at most once an hour, a snapshot of the web SAPI's OPcache status and production `php.ini` directives for `php-opcache` and `php-production-ini` (#172), because `excubia:check` runs on the CLI SAPI, whose values can differ. It is stored under the cache key `excubia:php-runtime:web` in the default cache store for 24 hours: booleans, the hit rate and memory percentages, the cached script count and the Unix time, never a path or an ini value. A request with a wrong or missing secret writes nothing. When the cache store fails, the request is still answered and the checks fall back to the CLI values.
