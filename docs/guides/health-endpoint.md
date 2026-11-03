# Health endpoint

In a Laravel 12 or 13 app, Excubia can serve its latest result in the [Oh Dear application health](https://ohdear.app/docs/features/application-health-monitoring) JSON format, the format [spatie/laravel-health](https://github.com/spatie/laravel-health) also uses, so external monitors can poll it. It is off by default. It complements the [push to excubia-app](./excubia-app.md); it is not the channel to the app.

This page is the setup. The request and response contract (fields, status mapping, the `crashed` and "not run yet" answers) is [`docs/health-endpoint.md`](../health-endpoint.md).

## 1. Turn it on

The package must be installed without `--dev`: `composer require qiick/excubia-core`.

```json
{
  "healthEndpoint": { "enabled": true, "path": "excubia/health" }
}
```

`path` is optional (default `excubia/health`). Reference: [configuration](./configuration.md#health-endpoint).

## 2. Set the secret

```bash
EXCUBIA_HEALTH_SECRET=a-long-random-string
```

The secret is read from the process environment only, never from `excubia.json`, so it is not committed. With `php artisan config:cache`, `.env` is not loaded into the process, so set it in the real environment (PHP-FPM pool, container, systemd unit). Enabled without a secret, the provider does not register the route, so it cannot be left open by mistake.

Check that the route exists:

```bash
php artisan route:list --name=excubia.health
```

With `php artisan route:cache`, the route is part of the cache: rebuild it after changing `healthEndpoint` or the secret.

## 3. Store a result on a schedule

The endpoint never runs checks in the request. It serves what the last `php artisan excubia:check` or `php artisan excubia:push` stored in `storage/excubia/health.json`:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('excubia:push')->daily();
Schedule::command('excubia:check --offline --fail-on=none --no-banner')->everyFiveMinutes();
```

- Oh Dear reports stale results when `finishedAt` is older than 10 minutes, so a frequent run is needed.
- `--offline` keeps the frequent run cheap: it reads the response cache and sends no request. The cache entries expire after `cache.ttlHours` (24 by default), so keep one online run per day, such as the daily `excubia:push` (or a daily `excubia:check` if you do not use `excubia-app`). Without it the offline checks turn `skipped`.
- `vendor/bin/excubia` never stores a result; only the Artisan commands do, and only while the endpoint is enabled.

Without the push, use `Schedule::command('excubia:check --fail-on=none --no-banner')->daily();` as the online run.

## 4. Point the monitor at it

In Oh Dear, enable Application health monitoring with the URL `https://your-app.example/excubia/health` and the same secret. Try it by hand:

```bash
curl -s -H "oh-dear-health-check-secret: $EXCUBIA_HEALTH_SECRET" https://your-app.example/excubia/health
```

A missing or wrong secret gets your app's normal `404`, so the route looks like a missing page. Before the first stored run, the answer is `200` with one `skipped` entry ("Not run yet").

## Notes

- An invalid `excubia.json` at boot: the route is not registered and the app boots normally; `excubia:check` reports the configuration error.
- The provider reads the default `excubia.json` of the app base path. A `--config` file passed to the commands decides whether that run stores a result, not whether the route exists.
- Findings removed by `ignore` rules are not counted. `--fail-on` does not change the status: a `warn` finding is `warning` whatever the threshold.
- The response holds check ids, names, statuses, severities, counts and package or runtime names. No messages, paths, hostnames, tokens or `.env` values.
