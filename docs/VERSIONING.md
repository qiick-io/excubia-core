# Excubia — Versioning and support

What you can rely on from `qiick/excubia-core`, from 1.0.0 onwards.

## Before 1.0

Excubia is in `0.x`. A `0.x` minor release (for example `0.2.0` to `0.3.0`) may break a public contract; the CHANGELOG says so. Patch releases (`0.2.0` to `0.2.1`) stay compatible.

The contracts below freeze at **1.0.0-beta** (#76). After the beta, only backwards-compatible fixes land before 1.0.0.

## Semantic Versioning

Excubia follows [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html):

- **Major** (`2.0.0`): breaks a public contract.
- **Minor** (`1.1.0`): adds something without breaking a contract.
- **Patch** (`1.0.1`): fixes a bug without breaking a contract.

### Public contracts

Changing any of these in an incompatible way is a breaking change, so it waits for a major:

- The report JSON (`docs/report-schema.json`, `schemaVersion`).
- Check ids and their meaning.
- Exit codes of `check`, `push` and `excubia:push-runtime`, and their precedence.
- CLI commands and options of `bin/excubia` (`check`, `push`, `cache:clear`).
- Artisan commands (`excubia:check`, `excubia:push`, `excubia:push-runtime`, `excubia:heartbeat` and the cache key it writes) and their options, and the entries the service provider schedules. There is no Artisan cache command: `cache:clear` exists only in `bin/excubia`.
- The `excubia.json` schema (`excubia.schema.json`) and the environment variables (`EXCUBIA_APP_URL`, `EXCUBIA_APP_TOKEN`, `EXCUBIA_HEALTH_SECRET`, `GITHUB_TOKEN`).
- The push envelope (`docs/push-schema.json`, `protocolVersion`) and the runtime and exception batch payloads.
- The health endpoint response, header, path default and its config keys.
- SARIF rule ids, the `excubiaFinding/v1` fingerprint key and the report `fingerprint` recipe.
- GitHub Action inputs and outputs.
- The `skipReason` values.

The full list, with source files, version fields, stability and the contract test that guards each one, is [`docs/CONTRACTS.md`](./CONTRACTS.md). A deliberate change to a contract updates the snapshot in `tests/Fixtures/Contracts/public-contracts.json`, `docs/CONTRACTS.md` and the CHANGELOG together.

### Not breaking

These can change in a minor or patch release:

- PHP classes. Everything under `src/` is internal (`docs/CONTRACTS.md`, "Internal"); do not extend or call it directly.
- The wording of finding messages, table output and Markdown output. Parse the JSON, not the text.
- Results that change because an upstream data source changed (endoflife.date, Packagist, GitHub, the npm registry, advisory databases). A new advisory or an EOL date can turn a green run red without a new Excubia release.
- New optional fields in the report JSON, the push payloads or `excubia.json`. Consumers must ignore fields they do not know.
- New check ids, commands or options. A new check can add findings to your report; pin it out with `--skip` or `checks.<id>.enabled: false` if needed.
- A change to a check's default severity. It is a minor and is listed in the CHANGELOG.

## Deprecation

When an option, command, config key or environment variable is to be removed:

1. It is marked deprecated in a minor release and listed in the CHANGELOG under **Deprecated**, with its replacement.
2. It keeps working, and Excubia prints a warning when it is used, for at least one minor release.
3. It is removed only in the next major, listed in the CHANGELOG under **Removed**.

## Support

This is the proposed policy for 1.0 onwards.

| | Supported |
| --- | --- |
| PHP | `^8.2` (the package floor; see `docs/SPEC.md` section 6) |
| Laravel, for the Artisan integration | 12 and 13 |
| Scanned projects | Any Composer 2 project |

Raising the PHP floor or dropping a Laravel version from the Artisan integration happens only in a major.

| Release line | Bug fixes | Security fixes |
| --- | --- | --- |
| Latest major | Yes | Yes |
| Previous major | Until the next major is released | For 6 months after the next major is released |
| Older majors | No | No |
| `0.x` (until 1.0) | Latest minor only | Latest minor only |

Report vulnerabilities as described in [`SECURITY.md`](../SECURITY.md).
