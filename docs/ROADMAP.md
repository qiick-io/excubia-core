# Excubia — Roadmap

Through v1.0.0, updated 2026-11-02. Source of truth: [`docs/SPEC.md`](SPEC.md) (decisions D1–D18, push protocol in section 13) and [`docs/ARCHITECTURE.md`](ARCHITECTURE.md). Issues labelled `needs-decision` are proposals, not approved work, even when they carry a milestone.

Both repositories are planned on one board: [Excubia project](https://github.com/orgs/qiick-io/projects/2). The excubia-app column shows which core version each app version needs.

| Core | Issues | Unlocks in excubia-app |
| --- | --- | --- |
| v0.1.x (released) | CLI, nine checks, config, cache, renderers | — |
| v0.2.0 — push | #72 `excubia push`; `runForSnapshot()` (PR #71) | v0.1.0: projects registered with a push token, reports received by push |
| v0.3.0 — SARIF + GitHub Action | #10, #11 | — |
| v0.4.0 — posture and health endpoint | #74 Oh Dear-compatible health endpoint (low priority); #73 posture checks (`needs-decision`) | — |
| v0.5.0 — runtime aggregates | #75 opt-in requests, jobs, scheduler, slow queries, outgoing HTTP (part of #20) | v0.5.0: runtime cards |
| v0.6.0 — exceptions | #77 opt-in exception capture with masking | v0.6.0: exceptions grouped into issues |
| v1.0.0 — contracts frozen | #76 report JSON, check ids, exit codes, CLI options, `excubia.json`, push envelope | v1.0.0: stable app |

excubia-app milestones in between (v0.2.0 findings views, v0.3.0 alerts, v0.4.0 EOL calendar) do not need a new core version.

## No milestone

| Issue | Status |
| --- | --- |
| #7, #8, #9 | Web scanner, postponed (D14) |
| #12 | Opt-in AI summary with the user's own key, `needs-decision` |
| #13, #14 | Proposed checks `env-example-sync`, `qiick-recommendations`, `needs-decision` |
| #15 | `--strict` for data-source failures, `needs-decision` |
| #16–#19 | GitHub App, PR comments, scheduled multi-repo monitoring, badges, `needs-decision` |
| #21 | `yarn.lock` and `pnpm-lock.yaml` support, `needs-decision` |

#20 (Phase B) is approved (D15) and tracked through #72, #74, #75 and #77.

## Not in this roadmap

- Permanent non-goals in SPEC section 2: paid features, qiick Cloud, writes or auto-fix in the scanned project, source-code analysis.
- Uptime and external probes (Phase B connectors): not planned before 1.0.0.
- Releases, tags and Packagist publishing stay human steps (`.cursor/rules/no-auto-release.mdc`).
