# Excubia — Roadmap

Through v1.0.0, updated 2026-11-03. Sources of truth:
- [`docs/SPEC.md`](SPEC.md): decisions D1–D18, push protocol in section 13.
- [`docs/ARCHITECTURE.md`](ARCHITECTURE.md).

Issues labelled `needs-decision` are proposals, not approved work, even when they carry a milestone.

Both repositories are planned on one board, the [Excubia project](https://github.com/orgs/qiick-io/projects/2). The excubia-app column shows which core version each app version needs.

## Path to 1.0.0-beta

**1.0.0-beta** is the first version that:
- is **feature-complete for 1.0**: push, SARIF and the GitHub Action, health endpoint, runtime aggregates, exception capture;
- has **frozen public contracts** (#76).

After the beta, only backwards-compatible fixes land before 1.0.0.

Priority labels: `priority:P0` blocks the beta, `P1` is required for the beta, `P2` is wanted, `P3` is low priority.

| Core | Issues (priority) | Unlocks in excubia-app |
| --- | --- | --- |
| v0.1.x (released) | CLI, nine checks, config, cache, renderers | — |
| v0.2.0 — push (done, not yet released) | #72 `excubia push`; `runForSnapshot()` (PR #71) | v0.1.0: projects registered with a push token, reports received by push |
| v0.3.0 — SARIF + GitHub Action | #10 SARIF renderer (P1), #11 GitHub Action (P1), #69 code cleanup (P2), #92 structured project and finding details in the report (P1, `needs-decision`) | v0.2.0 findings views and v0.4.0 EOL calendar, which need #92 |
| v0.4.0 — health endpoint | #74 Oh Dear-compatible health endpoint (P3); #73 posture checks (`needs-decision`) | — |
| v0.5.0 — runtime aggregates | #75 opt-in requests, jobs, scheduler, slow queries, outgoing HTTP (P1, part of #20) | v0.5.0: runtime ingest (excubia-app#48) and runtime cards (excubia-app#24) |
| v0.6.0 — exceptions | #77 opt-in exception capture with masking (P1) | v0.6.0: exception ingest (excubia-app#49) and exceptions grouped into issues (excubia-app#26) |
| **v1.0.0-beta — contracts frozen** | #76 contract freeze (P0), #82 security review (P0), #83 Laravel 11–13 test matrix (P1), #84 versioning and support policy (P1), #85 user documentation (P1), #86 coverage gate (P2), #87 beta release checklist (P1) | excubia-app v1.0.0-beta: ingest API frozen (excubia-app#50), security review (excubia-app#51), Docker image and self-hosting (excubia-app#25), beta checklist (excubia-app#52) |
| v1.0.0 — stable | #88 release checklist: no contract change since the beta, feedback triaged | v1.0.0: stable app |

excubia-app v0.3.0 (alerts) does not need a new core version. The v0.2.0 findings views (advisory exposure, finding lifecycle) and the v0.4.0 EOL calendar read the structured details from #92, so #92 lands before the contract freeze.

Order of work:
- #83 (Laravel matrix) and #84 (policy) can start at any time.
- #82 (security review) and #85 (docs) follow #74, #75 and #77.
- #76 closes the beta.

## Decisions needed before the beta

These change public contracts, so they are decided (in or out) before #76 freezes them:

| Issue | Question |
| --- | --- |
| #73 | Configuration posture checks through Artisan: new check ids in 1.0, or 1.x? |
| #15 | `--strict` for data-source failures: changes exit-code behaviour, so in 1.0 or never as a breaking change |
| #92 | Structured `project` and finding `details` in the report JSON for the excubia-app fleet views: which fields, and whether `schemaVersion` stays 1 |

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
