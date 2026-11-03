# Contributing to Excubia

Thanks for helping. The Composer package is `qiick/excubia-core`. Install it with `composer require qiick/excubia-core` (not `--dev`). The command stays `vendor/bin/excubia`. A few rules keep the codebase consistent:

1. Open or pick an issue first. One issue per pull request.
2. Branch from `develop` as `<type>/<issue>-<kebab-slug>` (types: feat, fix, refactor, chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format.
4. Open the PR against `develop` with `Closes #N`. PRs are squash-merged.
5. Before pushing run `composer test` (Pest), `composer lint` (Pint) and `composer analyse` (PHPStan). Pest 4 needs PHP 8.3 or newer to install. The package still installs on PHP 8.2 with `composer install --no-dev`.
6. Code style: strict types, explicit role suffixes, readonly `*Data` classes, English docblocks that explain *why*, no abbreviations.
7. Scope is defined by `docs/SPEC.md`. New features start as an issue labelled `needs-decision`.
8. Public contracts (report JSON, check ids, exit codes, CLI and Artisan commands and options, `excubia.json` schema and environment variables, push payloads, health endpoint, SARIF rule ids, GitHub Action inputs) are listed in [`docs/VERSIONING.md`](docs/VERSIONING.md). If your PR changes one, tick "Changes a public contract" in the PR template, say what changes in the summary, and update the schema, README and CHANGELOG together. Removals go through deprecation first.
