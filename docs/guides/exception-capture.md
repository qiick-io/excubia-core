# Exception capture

In a Laravel 12 or 13 app, Excubia can send the exceptions your app reports to your own `excubia-app`, masked and grouped by fingerprint. It is a separate opt-in, off by default, and works with [runtime monitoring](./runtime-monitoring.md) on or off. While it is off, nothing is registered on the exception handler.

It does not replace your error tracker: your own reporting (log channels, Sentry, Flare, other `reportable()` callbacks) runs exactly as before.

## Prerequisites

- The package installed without `--dev`: `composer require qiick/excubia-core`.
- An `excubia-app` URL and project token, set up as in [Push to excubia-app](./excubia-app.md#2-configure).
- The Laravel scheduler running (`schedule:run` from cron, or `schedule:work`).

## 1. Turn it on

```json
{
  "app": { "url": "https://excubia.example.com" },
  "exceptions": { "enabled": true }
}
```

Optional keys:

```json
{
  "exceptions": {
    "enabled": true,
    "maskPatterns": ["/ORD-[0-9]+/"],
    "ignore": ["App\\Exceptions\\PaymentDeclinedException"],
    "bufferMaxBytes": 1048576
  }
}
```

- `maskPatterns`: extra PCRE patterns, with delimiters, for data specific to your app. Matches become `[masked]`, before the built-in masking. An invalid pattern is a configuration error.
- `ignore`: exception classes, subclasses included, that are never captured. Exceptions your app does not report (`dontReport`, `ShouldntReport`) are never captured either.
- `bufferMaxBytes`: cap of the local buffer, default 1 MiB. The buffer is sent in batches of at most 512 KB, split again on a `413` answer, like runtime batches ([runtime monitoring](./runtime-monitoring.md#how-it-works)).

Reference: [configuration](./configuration.md#exception-capture).

## 2. Check the schedule

`php artisan schedule:list` shows `php artisan excubia:push-runtime` every minute. It is the same command as for runtime monitoring and sends both buffers.

## 3. Try it

```php
// routes/web.php, temporarily
Route::get('/excubia-test', fn () => throw new RuntimeException('Order for jane@example.com failed'))->name('excubia.test');
```

Open `/excubia-test`, then:

```bash
php artisan excubia:push-runtime
```

It prints `Sent 1 exception batch to excubia-app.`. In the app the message shows as `Order for [email] failed`, with the context `request` and the route `excubia.test`. Remove the route afterwards.

Exit codes are those of `excubia:push-runtime`: `0` sent or nothing to send, `2` configuration error, `3` the app rejected the token, answered an error or could not be reached (the data is kept). A failure on the exception buffer does not stop the runtime buffer, and the other way round.

## How it works

- Excubia adds one `reportable()` callback to Laravel's exception handler. It returns nothing, so the rest of the reporting chain runs, and every step catches its own errors, so capture never reaches your app.
- Each occurrence is masked first, then grouped in memory by fingerprint, context and environment, and written to `storage/excubia/exceptions/` when the request or command ends, when a queue worker loops, or once 100 groups are pending.
- The fingerprint is a sha256 of the class and the in-app stack frames (file relative to the project and `Class::method`, without line numbers). Moving code within a file does not split a group, and vendor frames are ignored.
- The context is the job class for exceptions thrown in a job, else the route name for a request, else the Artisan command name, else `unknown`.
- `excubia:push-runtime` sends the groups to `POST {url}/api/exceptions`, with the same batch files, `batchId` reuse until a 2xx answer, and size cap as the runtime buffer. The app adds up `count` and widens `firstSeen` / `lastSeen` for the same fingerprint.

Envelope: `protocolVersion` `1`, `type` `exceptions`, `batchId`, `sentAt` and `exceptions` (`{"groups": [...]}`). Schemas: [`docs/exception-schema.json`](../exception-schema.json) and `$defs.exceptionsEnvelope` in [`docs/push-schema.json`](../push-schema.json).

## What is sent

Per group: class, fingerprint, masked message (at most 1000 characters), stack frames (relative file, line, function, in-app flag; never arguments or local variables), the classes of chained exceptions, the context, the environment (`app.env`), the count and the first and last seen times. Never request payloads, headers, cookies, users, IP addresses, query bindings, `.env` values or absolute paths.

Built-in masking, applied before anything is written to disk: absolute paths (also Windows and UNC paths), URL credentials, hosts and query strings, host names in `host:` / `resolve host` phrases, quoted SQL literals, database hosts, JWTs, bearer and basic credentials, any other `Authorization` value, `password=` / `token=` / `api_key=` style values, emails, IP addresses, UUIDs, long hex strings and API-key-like strings, phone-like, social-security, card-like and long numbers. The exact rules are in the [privacy guide](./privacy.md#exception-capture).

## Turn it off

Set `"enabled": false` (or remove `exceptions`). The callback and, when runtime monitoring is off too, the schedule disappear at the next boot. What is left in `storage/excubia/exceptions/` is not sent.
