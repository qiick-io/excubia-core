# CI

Excubia is built to gate pipelines: it exits `1` when a finding is at or above `--fail-on`, and writes JSON, Markdown or SARIF for the job to keep. The standalone CLI does not boot the app, so a CI job needs only PHP and the project's `composer.lock`; no database, no `.env`, no `composer install` of the project itself.

## GitHub Action

The composite action in [`action.yml`](../../action.yml) runs `excubia check`, writes the Markdown report to the job summary and uploads the SARIF log to GitHub code scanning, so findings show up in the **Security** tab and on pull requests.

```yaml
# .github/workflows/excubia.yml
name: Excubia

on:
  push:
    branches: [main]
  pull_request:
  schedule:
    - cron: '0 6 * * 1'   # weekly: new advisories and EOL dates appear without code changes

permissions:
  contents: read
  security-events: write   # for the SARIF upload

jobs:
  excubia:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: qiick-io/excubia-core@v1
        with:
          fail-on: critical
          github-token: ${{ github.token }}
```

| Input | Default | Meaning |
| --- | --- | --- |
| `path` | `.` | Project directory to scan, relative to the workspace. |
| `fail-on` | `critical` | `critical`, `high`, `medium`, `low` or `none`, as `--fail-on`. Note the default is `critical` here and `high` in the CLI. |
| `only` | — | Comma-separated check ids to run. |
| `skip` | — | Comma-separated check ids to skip. |
| `github-token` | — | Optional. Raises the GitHub API rate limit for `composer-stale`. Passed to Excubia as `GITHUB_TOKEN` only. |
| `upload-sarif` | `true` | `false` skips the code scanning upload (and the need for `security-events: write`). |
| `php-version` | `8.4` | PHP used to run Excubia (8.2 or newer), not the scanned project's PHP. |

| Output | Meaning |
| --- | --- |
| `sarif-file` | Path of the SARIF log. |
| `exit-code` | Exit code of `excubia check`. |

How it works:

- Excubia is installed with Composer in `$RUNNER_TEMP/excubia` from the action's own checkout, so the version matches the ref you use (`@v1`, a tag or a commit SHA). Its dependencies (`symfony/console`, `symfony/http-client`, `composer/semver`…) come from the lock committed in `.github/action-install/` and resolved for PHP 8.2, so the action runs the same reviewed versions on every run and every `php-version` from 8.2 up; Composer plugins and scripts are disabled. The scanned project's code and dependencies are never installed or executed.
- The SARIF log is uploaded even when the step then fails on `fail-on`. Pull requests from forks get a read-only token, so the upload is skipped there.
- `excubia.json` in `path` is read as usual, so ignore rules and thresholds apply.
- Pin the action to a commit SHA if your policy requires it.

## Deploy markers

Run `excubia push --deploy` as the step after the deploy. It sends the report like `excubia push`, plus `deployedAt`, so `excubia-app` draws a deploy marker and can show what changed with that release ("this exception started with release X", "slow requests doubled after the deploy").

```yaml
# GitHub Actions, after the deploy step
- run: vendor/bin/excubia push --deploy
  env:
    EXCUBIA_APP_URL: ${{ vars.EXCUBIA_APP_URL }}
    EXCUBIA_APP_TOKEN: ${{ secrets.EXCUBIA_APP_TOKEN }}
```

On the server, `php artisan excubia:push --deploy` as the last line of the deploy script does the same and includes the posture checks.

The release comes from `release` in `excubia.json`, `EXCUBIA_RELEASE`, or the variable the platform already sets (`GITHUB_SHA`, `CI_COMMIT_SHA`, `VERCEL_GIT_COMMIT_SHA`, `FORGE_DEPLOY_COMMIT`, `LARAVEL_CLOUD_COMMIT`); `vendor/bin/excubia` falls back to the commit in the project's `.git`, read from the files without running `git`. Order and rules: [release identifier](./configuration.md#release-identifier). Without any of them the marker has no release and a warning goes to stderr. Set `EXCUBIA_RELEASE` in the environment of the app as well, so runtime and exception batches carry the same release.

## SARIF

`--format=sarif` writes a [SARIF 2.1.0](https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html) log:

- Each check id is a rule; each `fail` or `warn` finding is a result. `pass` and `skipped` rows and the upgrade plan are left out.
- Levels: `critical` and `high` → `error`, `medium` → `warning`, `low` and `info` → `note`. The Excubia severity stays in `properties.severity`.
- The location is the manifest the finding comes from (`composer.json`, `composer.lock`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `.nvmrc`, `.node-version` or `package.json`; for the posture checks `config/app.php`, `config/queue.php`, `database/migrations` or `routes/console.php`), relative to the project. Never an absolute path.
- `partialFingerprints` come from the check id, subject and advisory id, so an alert keeps its identity across runs.

## Any other CI

Install Excubia as a dev dependency and run it. The exit code fails the job.

```bash
composer install --no-interaction --no-scripts   # or only: composer require --dev qiick/excubia-core
vendor/bin/excubia check --no-banner --fail-on=high
```

Keep the reports as artifacts:

```bash
vendor/bin/excubia check --no-banner --format=json --output=excubia.json --fail-on=none
vendor/bin/excubia check --no-banner --format=markdown --output=excubia.md
```

The second run reuses the response cache of the first one. Typical choices:

| Need | Command |
| --- | --- |
| Fail only on critical findings | `--fail-on=critical` |
| Report, never fail | `--fail-on=none` |
| Fail when a data source was down, rather than pass with `skipped` checks | `--strict` (exit `3`) |
| Production dependencies only | `--no-dev` |
| No network in the job | Restore a cached directory set with `cache.directory`, then `--offline` |
| Scan a sub-directory | `vendor/bin/excubia check path/to/app` |

GitLab CI example:

```yaml
excubia:
  image: php:8.4-cli
  before_script:
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --no-interaction --no-scripts --no-progress
  script:
    - vendor/bin/excubia check --no-banner --format=markdown --output=excubia.md
  artifacts:
    when: always
    paths: [excubia.md]
```

Exit codes: `0` clean, `1` finding at or above `--fail-on`, `2` usage or configuration error, `3` data-source failure in strict mode. Precedence `2` > `1` > `3`. See the [README](../../README.md#exit-codes).

## Push from CI

To send the report to your own `excubia-app` after a deploy, see [Push to excubia-app](./excubia-app.md#after-a-deploy).

## Posture checks in CI

The `laravel-*` posture checks after `laravel-next-blocker` read the booted app, so they only run through `php artisan excubia:check`. In CI they would describe the CI environment, not production, so use `vendor/bin/excubia` there (the posture checks are `skipped`), and run the posture checks on the server or through the scheduled push.
