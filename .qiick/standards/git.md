<!-- Generated from qiick-io/app-ui standards: do not edit, run the sync. The agent files hold the core of this page and point here. -->

# Git, commits and pull requests

- **English everywhere in the repo:** code, comments, docs, commit messages, issues, pull requests.
- **Conventional Commits:** `type(scope): summary` in the imperative, lower case, no trailing period. Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `ci`, `build`, `security`. `!` or a `BREAKING CHANGE:` footer for breaking changes. commitlint checks PR titles in CI through the reusable `qiick-io/app-ui/.github/workflows/pr-title.yml` (check `pr-title / commitlint`), which every repo calls on `pull_request` with the `edited` type.
- **One issue, one branch, one pull request.** Branch from `develop` as `<type>/<issue>-<kebab-slug>` (`feat/42-project-registration`). Never commit directly to `develop` or `main`.
- Pull requests target `develop` and are **squash-merged**, so the PR title becomes the commit: make it a valid Conventional Commit. The body says what changed, why, and `Closes #<issue>`.
- Never force-push a shared branch, never `--no-verify`, never `--admin` or any branch-protection bypass.
- No attribution or `Co-authored-by` trailers for tools or agents.
- Never commit `.env`, secrets, tokens, personal phone numbers or email addresses; only `.env.example`.
- Ask the maintainer before: a new dependency, anything outside the issue's scope, repo settings (branch protection, required checks, secrets), paid services or accounts.
