# Push to excubia-app

`excubia push` runs the same checks as `excubia check` and sends the JSON report to your own self-hosted [`excubia-app`](https://github.com/qiick-io/excubia-app), the multi-project dashboard. The app never reads your project or polls it: projects push. Nothing is sent until you configure an app URL and a project token.

## 1. Get a URL and a token

In `excubia-app`, register the project and copy its push token. The URL is the app's base URL, for example `https://excubia.example.com`.

## 2. Configure

Set both in the environment:

```bash
EXCUBIA_APP_URL=https://excubia.example.com
EXCUBIA_APP_TOKEN=your-project-token
```

Or put the URL in `excubia.json` and keep only the token in the environment:

```json
{ "app": { "url": "https://excubia.example.com" } }
```

The environment wins over `app.url` / `app.token`. Keep the token out of committed files: `app.token` still works, but `check`, `push`, `excubia:check`, `excubia:push` and `excubia:push-runtime` print a warning on stderr when it is set (`app.token is set in excubia.json; prefer EXCUBIA_APP_TOKEN so the token is not committed.`). Excubia reads the variables with `getenv()`, not through Laravel config: with `php artisan config:cache`, `.env` is not loaded into the process, so set them in the real process environment (PHP-FPM pool, container, systemd unit, CI secrets).

The scheduler runs each command in its own process, so the variables must also be set where `schedule:run` runs (the cron or `schedule:work` environment), not only for the web server. A scheduled push without a token exits `2` and shows as failed in the scheduler.

The URL must be HTTPS. Plain HTTP is accepted only for `localhost`, `127.0.0.1` and `[::1]`, which is useful to try it against a local app.

## 3. Send a report

```bash
vendor/bin/excubia push        # any project
php artisan excubia:push       # Laravel; also sends the posture checks
```

On success it prints `Report sent to excubia-app (batch <uuid>).` and exits `0`, whatever the findings.

| Exit code | Meaning |
| --- | --- |
| `0` | Sent. Findings do not change it. |
| `2` | Configuration error: missing URL or token, plain HTTP to a host other than localhost, missing `composer.lock`, invalid `excubia.json`. |
| `3` | The app rejected the token (401/403), answered an error, or could not be reached. With `--strict`, also a check skipped because a data source failed: then nothing is sent. |

`push --strict` (or `"strict": true`) makes sure the app never stores an incomplete report as if it were the full picture.

## From the scheduler

Send a report every day, so the app sees new advisories and EOL dates even when nobody deploys:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('excubia:push')->daily();
```

The server needs the usual scheduler cron:

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Install the package without `--dev` (`composer require qiick/excubia-core`) so the command exists in production.

## After a deploy

Push from the deploy job so the app reflects what is live:

```yaml
# GitHub Actions, after the deploy step
- run: vendor/bin/excubia push
  env:
    EXCUBIA_APP_URL: ${{ vars.EXCUBIA_APP_URL }}
    EXCUBIA_APP_TOKEN: ${{ secrets.EXCUBIA_APP_TOKEN }}
```

Or run `php artisan excubia:push` on the server as the last step of the deploy script (Envoyer, Forge, Deployer hooks), which also includes the posture checks of the deployed app.

## Stale projects

The app marks a project stale when no report arrives within a window you configure in the app. A stale project usually means the scheduler stopped or the token was revoked, so keep the daily scheduled push even if you also push on deploy.

## What is sent

`POST {url}/api/reports` with `Authorization: Bearer <token>`, over TLS with the same timeouts (connect 5 s, total 15 s) and limited retries as the data sources. The body is an envelope:

```json
{
  "protocolVersion": 1,
  "batchId": "5b0d2c9e-7a51-4f0e-9a43-2f3f0f2a6c11",
  "sentAt": "2026-11-03T06:00:00Z",
  "report": { "schemaVersion": 1, "...": "the JSON report" }
}
```

- `batchId` is a new UUID per run, kept across HTTP retries, so a retried send is stored once.
- `report` is the same document as `--format=json` ([`docs/report-schema.json`](../report-schema.json)): package names and versions, runtime versions and findings, with `generatedAt`, `excubiaVersion`, `project` and the per-finding `fingerprint` and `details` the app uses for its fleet views. Envelope schema: [`docs/push-schema.json`](../push-schema.json).
- The token is never printed, cached or written to a report.

Full list per feature: [privacy](./privacy.md).
