# Excubia — Health endpoint

An opt-in HTTP endpoint that serves the latest Excubia result in the [Oh Dear application health](https://ohdear.app/docs/features/application-health-monitoring) JSON format, the same format [spatie/laravel-health](https://github.com/spatie/laravel-health) uses. External monitors can poll it. It complements `excubia push` (SPEC §13, D18) and is not the channel to `excubia-app`.

The response shape and the config keys below are a public contract (`docs/VERSIONING.md`), frozen with the other contracts at 1.0.0-beta (#76).

## Enable it

The endpoint is registered by `Qiick\Excubia\Laravel\ExcubiaServiceProvider`, so it exists only in Laravel apps. It is off by default.

1. Turn it on in `excubia.json` at the project root:

   ```json
   {
     "healthEndpoint": { "enabled": true, "path": "excubia/health" }
   }
   ```

   | Key | Type | Default | Meaning |
   | --- | --- | --- | --- |
   | `healthEndpoint.enabled` | boolean | `false` | Register the `GET` route. |
   | `healthEndpoint.path` | string | `excubia/health` | URL path of the route. Leading and trailing `/` are ignored. Letters, digits, `.`, `_`, `~`, `-` and `/` only. |

2. Set the secret in the environment:

   ```bash
   EXCUBIA_HEALTH_SECRET=a-long-random-string
   ```

   The secret is read from the process environment only (like `EXCUBIA_APP_TOKEN`), never from `excubia.json`, so it is not committed. With `php artisan config:cache`, `.env` is not loaded, so set it in the real process environment (PHP-FPM pool, container, systemd unit). **Enabled without a secret, the provider does not register the route**, so it cannot be left open by mistake.

3. Store a result on a schedule. The endpoint never runs checks in the request; it serves what the last `php artisan excubia:check` or `php artisan excubia:push` stored:

   ```php
   // routes/console.php
   use Illuminate\Support\Facades\Schedule;

   Schedule::command('excubia:push')->daily();                                      // fills the response cache
   Schedule::command('excubia:check --offline --fail-on=none --no-banner')->everyFiveMinutes();
   ```

   Oh Dear reports stale results when `finishedAt` is older than 10 minutes, so a frequent run is needed. `--offline` keeps that run cheap: it reads the response cache (TTL 24 h by default) and sends no request. `bin/excubia` does not store a result; only the Artisan commands do, and only while the endpoint is enabled.

4. In Oh Dear, enable Application health monitoring with the URL `https://your-app.example/excubia/health` and the same secret.

`excubia.json` invalid at boot? The route is not registered and the app boots normally; `excubia:check` reports the configuration error.

The provider reads the default `excubia.json` of the app base path. A `--config` file passed to the commands decides whether that run stores a result, but not whether the route exists. With `php artisan route:cache` the route is part of the cache: rebuild it after changing `healthEndpoint`.

## Request

```
GET /excubia/health
oh-dear-health-check-secret: <EXCUBIA_HEALTH_SECRET>
```

The header is compared with `hash_equals`. A missing, empty or wrong secret gets the app's normal `404`, so the route is indistinguishable from a missing page. The route has no middleware group (no session, no CSRF).

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
| `checkResults[]` | list | One entry per check of the stored run, in report order (at most 8, or the single `excubia-run` entry). |
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

The payload holds check ids, check names, statuses, severities, counts and package or runtime names. Finding messages, fix hints and evidence URLs are not copied, so no absolute path, hostname, token or `.env` value reaches the response.
