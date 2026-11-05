# Excubia — Roadmap

Through v1.0.0, updated 2026-11-05. Sources of truth:
- [`docs/SPEC.md`](SPEC.md): decisions D1–D19, push protocol in section 13.
- [`docs/ARCHITECTURE.md`](ARCHITECTURE.md).
- [`docs/CONTRACTS.md`](CONTRACTS.md): the public contracts frozen for 1.0.

Issues labelled `needs-decision` are proposals, not approved work, even when they carry a milestone.

Both repositories are planned on one board, the [Excubia project](https://github.com/orgs/qiick-io/projects/2).

## One milestone: v1.0.0-beta.1

Core and excubia-app both work toward a single milestone, **v1.0.0-beta.1**, with no intermediate versions. The v0.2.0–v0.6.0 milestones are closed and their work is part of the beta.

**1.0.0-beta.1** is the first version that:
- is **feature-complete for 1.0**: push, SARIF and the GitHub Action, health endpoint, posture checks, runtime aggregates, exception capture, structured report details for excubia-app;
- has **frozen public contracts** (#76, `docs/CONTRACTS.md`).

After the beta, only backwards-compatible fixes land before 1.0.0.

| Milestone | Core | excubia-app |
| --- | --- | --- |
| v0.1.x (released) | CLI, nine checks, config, cache, renderers | — |
| **v1.0.0-beta.1** | Done: push #72, SARIF #10, GitHub Action #11, health endpoint #74, posture checks #73, runtime aggregates #75, exception capture #77, `--strict` #15, report details #92 and #123, privacy `skipPackages` #108, contract freeze #76, security review #82, Laravel 12–13 matrix #83, versioning policy #84, user documentation #85, coverage gate #86. Open: beta release checklist #87. | Runs against the beta: report push, runtime and exception ingest, alerts, findings views, self-monitoring, beta checklist (excubia-app milestone v1.0.0-beta.1) |
| v1.0.0 — stable | #88 release checklist: no contract change since the beta, feedback triaged | Stable app |

## Decisions needed before the beta

These change public contracts, so they were decided before #76 froze them (`docs/CONTRACTS.md`):

| Issue | Question | Decision |
| --- | --- | --- |
| #73 | Configuration posture checks through Artisan: new check ids in 1.0, or 1.x? | In 1.0 (PR #100): six `laravel-*` check ids |
| #15 | `--strict` for data-source failures: changes exit-code behaviour, so in 1.0 or never as a breaking change | In 1.0 (PR #97): opt-in `--strict` / `strict`, exit `3` |
| #92 | Structured `project` and finding `details` in the report JSON for excubia-app: which fields, and whether `schemaVersion` stays 1 | In 1.0: optional `generatedAt`, `excubiaVersion`, `project`, finding `fingerprint` and `details`; `schemaVersion` stays `1` |

## No milestone

These do not block 1.0 and can land in 1.x as additive features:

| Issue | Status |
| --- | --- |
| #7, #8, #9 | Web scanner, postponed (D14) |
| #12 | Opt-in AI summary with the user's own key, `needs-decision` |
| #13, #14 | Proposed checks `env-example-sync`, `qiick-recommendations`, `needs-decision` |
| #16–#19 | GitHub App, PR comments, scheduled multi-repo monitoring, badges, `needs-decision` |
| #21 | `yarn.lock` and `pnpm-lock.yaml` support, `needs-decision` |

#20 (Phase B) is approved (D15) and tracked through #72, #74, #75 and #77.

## Not in this roadmap

- Permanent non-goals in SPEC section 2: paid features, qiick Cloud, writes or auto-fix in the scanned project, source-code analysis.
- Uptime and external probes (Phase B connectors): not planned before 1.0.0.
- Releases, tags and Packagist publishing stay human steps (`.cursor/rules/no-auto-release.mdc`).
