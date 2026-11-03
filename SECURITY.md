# Security Policy

## Supported versions

Security fixes follow the support policy in [`docs/VERSIONING.md`](docs/VERSIONING.md).

| Version | Security fixes |
| --- | --- |
| `1.x`, latest minor | Yes. Fixes ship as a `1.x` patch or minor; upgrading within `1.x` never breaks a public contract. |
| `1.x`, older minors | No. Upgrade to the latest `1.x` minor. |
| Previous major (from `2.0.0` on) | For 6 months after the next major is released. |
| Latest `0.x` minor | Yes, until `1.0.0` is released. |
| Older `0.x` minors | No. |

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" on the Security tab of `qiick-io/excubia-core`). We aim to acknowledge reports within 72 hours.

Excubia sends only package names and versions to public data sources and never transmits file contents, secrets or `.env` values. The only other destination is your own `excubia-app`, through the opt-in push, runtime monitoring and exception capture. Reports showing otherwise are treated as critical.

## Security model

[`docs/SECURITY-MODEL.md`](docs/SECURITY-MODEL.md) lists every data path (public data sources, response cache, push, runtime and exception buffers, health endpoint, GitHub Action): what is sent or stored, where it goes, the controls, how to turn it off, the threats considered and the known residual risks.
