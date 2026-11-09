<!-- qiick-standards:begin. Generated from qiick-io/app-ui standards: do not edit inside this block, run the sync (see qiick-standards.json). -->

# qiick standards

These rules bind every agent and contributor in this repo from the first task. They are the same in every qiick repo (source: https://github.com/qiick-io/app-ui/tree/develop/standards), and CI fails when this block differs from the source. Rules for this repo only live outside this block; where they are more specific, they win. Starting a new qiick product: https://github.com/qiick-io/app-ui/blob/develop/docs/new-product.md.

## Code standard

Every file follows classic craftsmanship, including files nobody reads line by line: self-explanatory names, English documentation of _why_ and of domain invariants, clean structure with breathing room, and no duplication.

### Simplest thing that works

Stop at the first rung that holds: YAGNI, standard library, platform, installed dependency, one line, then the minimum code.

- No unrequested abstractions, dependencies or boilerplate. A new Composer or npm dependency needs the maintainer's OK.
- Deletion over addition. Boring over clever. Fewest files possible.
- Mark an intentional shortcut with a `ponytail:` comment that names the ceiling and the upgrade path.
- Never lazy about trust-boundary validation, data-loss prevention, security, accessibility, or anything explicitly requested.
- Non-trivial logic leaves one runnable check behind (a test).

### Casing

PHP / Laravel (Pint `laravel` preset, PSR-4, PER-CS):

- Classes, interfaces, traits, enums and their `.php` files: `PascalCase` (`StoreCollectionItemRequest.php`, 1:1 with PSR-4).
- Methods, functions, properties, variables: `camelCase` (`$authenticatedUser`, `resolveEffectivePermissions()`).
- Enum cases: `PascalCase` (`CollectionStatusEnum::PendingReview`).
- Database tables and columns, `config/*.php` keys: `snake_case` (`created_by_user_id`).
- Artisan commands and URL slugs: `kebab-case` (`externa:sync-permissions`, `/collection-items`).

React / TypeScript / Inertia:

- Components, `type`, `interface` and `enum` names: `PascalCase` (`CollectionItemDrawer`, `CollectionItemProps`).
- Functions, hooks, variables, props: `camelCase` (`useAccessibilityPreferences`, `handleSubmission`).
- **Files and directories on disk: `kebab-case`** (`collection-item-form.tsx`, `use-accessibility-preferences.tsx`), so macOS (case-insensitive) and Linux / Docker (case-sensitive) never disagree. No barrel `index.ts` files that only re-export. CI checks the file names.

### Names: full words

- No single letters (`x`, `e`, `q`) and no abbreviations (`cfg`, `res`, `req`, `val`, `arr`, `tmp`, `idx`, `usr`, `ctx`, `def`, `cb`). Full words even at 30+ characters (`$collectionFieldDefinition`, `handleFormSubmissionError`).
- `i`, `j`, `k` only as indices of tight numeric loops; prefer `foreach ($collectionItems as $collectionItem)`, `for...of`, `.map()`.
- **Booleans** (variables, columns, custom props, boolean-returning methods) start with `is`, `has`, `can` or `should` (`$isEmailVerified`, `canManageCollection()`, `shouldBroadcastImmediately`). Exempt: names fixed by a framework or library contract (see [Contracts](#contracts-never-break-them)).
- **Collections** use specific plurals (`$publishedArticles`) or explicit maps (`$usersIndexedById`), never `$data`, `$list`, `$arr`.

### Role suffixes

Only Eloquent models use bare domain nouns (`User`, `CollectionItem`). Every other class of our own ends with its role, so neither a human nor an agent mistakes a model for a request, enum, event or job:

| Directory                         | Suffix         | Notes                                                   |
| --------------------------------- | -------------- | ------------------------------------------------------- |
| `app/Models/`                     | none           | Relationships, casts, accessors, scopes, constants only |
| `app/Http/Controllers/`           | `Controller`   |                                                         |
| `app/Http/Requests/`              | `Request`      | Validation and authorization                            |
| `app/Http/Resources/`             | `Resource`     |                                                         |
| `app/Http/Responses/`             | `Response`     |                                                         |
| `app/Http/Middleware/`            | `Middleware`   |                                                         |
| `app/Actions/`                    | `Action`       | One write / use case, one public `execute(...)`         |
| `app/Services/`                   | `Service`      | Orchestration, complex reads, external integrations     |
| `app/Data/`                       | `Data`         | `readonly class` DTOs and value objects                 |
| `app/Enums/`                      | `Enum`         | Backed values never change without a data migration     |
| `app/Policies/`                   | `Policy`       |                                                         |
| `app/Jobs/`                       | `Job`          |                                                         |
| `app/Console/Commands/`           | `Command`      |                                                         |
| `app/Events/`                     | `Event`        | Keep `broadcastAs()` wire names stable                  |
| `app/Listeners/`                  | `Listener`     |                                                         |
| `app/Notifications/`              | `Notification` |                                                         |
| `app/Observers/`                  | `Observer`     |                                                         |
| `app/Rules/`                      | `Rule`         |                                                         |
| `app/Exceptions/`                 | `Exception`    |                                                         |
| `app/Builders/`                   | `Builder`      | Dedicated query builders when scopes grow large         |
| `app/Contracts/`                  | `Contract`     | Interfaces                                              |
| traits (one place: `app/Traits/`) | `Trait`        |                                                         |
| `tests/`                          | `Test`         | `*Test.php`, `*.test.ts(x)`                             |

Do not invent other role nouns (`...Normalizer`, `...Publisher`, `...Recorder`, `...Writer`) or split domain classes arbitrarily between `app/Services/` and `app/Support/`: a write is an `Action`, an orchestrator or read pipeline is a `Service`. Packages apply the same table to their own namespace (`Qiick\Excubia\...`); a package may add roles of its own (`Parser`, `Renderer`, `Check`), listed in its architecture test config.

**Never rename framework or package scaffolding.** Files native to Laravel or published by a package keep their upstream names: Fortify actions (`app/Actions/Fortify/*`), starter-kit files (`HandleInertiaRequests`, `HandleAppearance`, `app/Concerns/*`), default service providers, vendor-published files (Spatie, Horizon, Telescope, Pennant, Passport), and frontend primitives (`shadcn/ui` files in `components/ui/`). They are listed in the repo's `tests/Arch/naming-architecture.php`, never renamed.

### Architecture: five layers

1. **Thin controllers.** At most the 5 resource methods or one `__invoke`, about 10 to 15 lines each, doing three things: receive a typed `*Request`, call an `*Action` / `*Service` with a `*Data` DTO, return a `*Resource`, JSON or an Inertia page. No business logic, no `DB` facade.
2. **Domain layer.** Writes in `app/Actions/*Action.php`, orchestration and reads in `app/Services/*Service.php`, typed `readonly` DTOs in `app/Data/*Data.php` instead of `array<string, mixed>` between controllers, actions, services and jobs.
3. **Clean models.** Relationships, `casts()`, accessors / mutators, scopes (or an `app/Builders/*Builder.php`), table constants. Never HTTP calls, disk or S3 work, or multi-step orchestration in a model.
4. **Authorization in Policies**, called through `Gate::authorize()` or a FormRequest `authorize()`, not scattered checks.
5. **One domain for every surface.** The Inertia UI, the REST / GraphQL API, MCP / AI tools and the CLI call the same `*Action` and `*Service` classes; domain logic is never duplicated per surface.

The Pest architecture test (`tests/Arch/NamingArchitectureTest.php`) enforces the suffixes, readonly DTOs, `declare(strict_types=1)`, no debugging helpers, no `env()` outside `config/`, no `DB` facade in controllers, and at most five public methods per controller (existing debt ratcheted in `naming-architecture.php`).

### Formatting

- Curly braces on every `if`, `else`, `for`, `foreach`, `while`, `do`. No single-line braceless control statements.
- A blank line before and after control-flow blocks (`if`, `return`, `for`, `foreach`, `while`, `switch`, `try`, `throw`); ESLint `@stylistic/padding-line-between-statements` and Pint enforce it where configured.
- `declare(strict_types=1);` in every PHP file you write or touch.
- No `dd`, `dump`, `ray`, `var_dump`, `console.log` left behind. No `env()` outside `config/`.
- Strict DRY: extract shared validation rules, scopes, DTO shapes and UI primitives instead of copying blocks.

### Documentation (English PHPDoc / JSDoc)

This replaces the "comment only exceptionally complex logic" default of Laravel Boost and similar guidelines.

- **Class, component and hook docblock** (mandatory): the unit's responsibility and why it exists.
- **Method and function docblock** for what the signature cannot say:
    1. Array shapes and generics for PHPStan / Larastan (`@param array{title: string, slug: string} $validatedPayload`, `@return Collection<int, CollectionItem>`).
    2. Side effects: queued jobs, dispatched events, filesystem / S3 writes, external HTTP calls.
    3. Business invariants, why a guard exists, `@throws` conditions.
- No noise that restates the code (`// set loading to true`).

### Contracts: never break them

1. **Migrations are forward-only.** Never edit, rename or delete a migration in `database/migrations/` once it is merged into a released version: existing self-hosted installs would no longer upgrade. A schema or stored-data change is a new migration with a safe backfill. The reusable `migrations-frozen.yml` fails CI when a migration on `main`, on the latest tag or in `database/migrations/.frozen` changes. A repo that has not released yet may state a pre-release exception in its own section.
2. **Framework method contracts keep their names** (no `is` / `has` prefix forced on them): `FormRequest::authorize()`, Policy methods (`viewAny`, `view`, `create`, `update`, `delete`, `restore`, `forceDelete`), `Rule::passes()` / `ValidationRule::validate()`, `broadcastWhen()`, `shouldQueue()`.
3. **Native and library props keep their names:** `disabled`, `required`, `checked`, `open`, `defaultOpen`, `readOnly`, `asChild`, `modal`, `preserveScroll`, `preserveState`, `replace` (DOM, React, Radix / shadcn, Inertia). The prefix rule applies only to our own props and state.
4. **Wire formats stay stable** when a PHP class is renamed: REST JSON keys, GraphQL type and field names, webhook payloads, `broadcastAs()` names, Inertia page paths, named routes, morph maps (`Relation::enforceMorphMap`), queued job class names. Enum backing values stay identical.
5. **Convention-driven directories** (`app/GraphQL/`, `app/Ai/`, MCP tools) keep the names their resolver expects unless the resolver mapping changes in the same PR.
6. **Mechanical renames and behaviour changes never share a PR.** Land the `git mv` + import updates first with green tests, then extract or change logic in a separate PR.

## Backend conventions

How the five layers look in a qiick app. Each topic has a page with the externa-core (or excubia-app) files to copy; read it before touching that area.

| Topic                                                  | Page                                                                          |
| ------------------------------------------------------ | ----------------------------------------------------------------------------- |
| Auth pages, 2FA, passkeys, SSO through Signaculum      | https://github.com/qiick-io/app-ui/blob/develop/docs/auth.md                  |
| Roles, permissions, policies, `<app>:sync-permissions` | https://github.com/qiick-io/app-ui/blob/develop/docs/roles-and-permissions.md |
| Error pages 403 / 404 / 500 / 503                      | https://github.com/qiick-io/app-ui/blob/develop/docs/error-pages.md           |
| REST API, OpenAPI, API keys and scopes                 | https://github.com/qiick-io/app-ui/blob/develop/docs/api.md                   |
| Realtime: Reverb, channels, presence                   | https://github.com/qiick-io/app-ui/blob/develop/docs/realtime.md              |
| Security headers                                       | https://github.com/qiick-io/app-ui/blob/develop/docs/security-headers.md      |
| Logging and activity (audit) log                       | https://github.com/qiick-io/app-ui/blob/develop/docs/logging-and-audit.md     |
| AI backend: providers, tools, approvals                | https://github.com/qiick-io/app-ui/blob/develop/docs/ai-backend.md            |

### Requests and controllers

- Every write takes a `FormRequest` in `app/Http/Requests/<Area>/`: `Store<Model>Request`, `Update<Model>Request`, `Bulk<Model>ActionRequest`; shared rules live in `app/Http/Requests/Concerns/*Trait.php` (`ValidatesListPerPageTrait`, `ValidatesSearchQueryTrait`). `authorize()` checks the permission (or the policy); `rules()` validates the whole payload at the trust boundary.
- A controller method receives the request, builds a `*Data` DTO from `validated()`, calls one `*Action` (write) or `*Service` (read), and returns an Inertia page, a redirect with a flash message, or a `*Resource`. Index pages paginate on the server (`?page`, `?per_page`, `?sort`, `?search`).
- Wayfinder generates the frontend route helpers (`@/routes/...`, `@/actions/App/Http/Controllers/...`): never hard-code a URL in TypeScript.

### Permissions and policies

- Permissions are a string-backed `app/Enums/PermissionEnum.php` (`CanShowUsers = 'can-show-users'`, `can-<verb>-<resource>`), roles a `RoleEnum` (`super-admin`, `admin`, …), stored with `spatie/laravel-permission`.
- `php artisan <app>:sync-permissions` creates the missing permissions from the enum, grants new ones to `admin`, clears the permission cache and writes `resources/js/enums/permission-enum.ts`. `<app>:install` and `<app>:upgrade` run it; never seed permissions by hand.
- Route groups are gated with the `permission:<value>` middleware; record-level decisions (ownership, state) live in `app/Policies/*Policy.php`, called through `Gate::authorize()` or `authorize()`. `Gate::before` grants `super-admin` everything.
- The UI receives `auth.permissions` as a shared Inertia prop and hides what the user cannot do (`useCan()`); the server check is the one that counts.

### API

Only when the spec asks for one: REST under `/api/v1`, `*Resource` responses, a hand-written OpenAPI file served at `/api/v1/openapi.json`, hashed API keys with scopes, named rate limiters. Details: https://github.com/qiick-io/app-ui/blob/develop/docs/api.md

### Security

- `SetSecurityHeadersMiddleware` on the `web` group (CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`), configured in `config/security.php`; HSTS belongs on the reverse proxy.
- Rate limiters for login, 2FA, passkeys and sensitive actions; `DB::prohibitDestructiveCommands(app()->isProduction())`; secrets stored encrypted (`encrypted` cast), never logged.
- Outbound URLs that users configure (OIDC issuers, webhooks) are SSRF-validated before the request.

### Logging and audit

- Every domain model a user can change uses `app/Traits/LogsApplicationActivityTrait.php` (`spatie/laravel-activitylog`: all attributes, only dirty, never empty), excluding secrets (`password`, `two_factor_*`, `remember_token`, key hashes).
- Log names: `default`, `auth`, `settings`, `ai`, plus one per domain area; `ip` and `user_agent` are added to every entry. The activity log is a page behind `can-show-activity-logs`.
- Application logs go to the stock `config/logging.php` channels (`stack` → `single` / `daily`, `stderr` in Docker). No personal data or secrets in log messages.

### Realtime

Broadcasting is optional: `BROADCAST_CONNECTION=null` must leave the app fully usable. Events are `app/Events/*Event.php` with `ShouldBroadcastNow` and a stable `broadcastAs()`; channels are `App.Models.User.{id}` (private), `online` (presence) and `<resource>.{id}` (presence when people collaborate), each authorised in `routes/channels.php` with `is_active` checked. Details: https://github.com/qiick-io/app-ui/blob/develop/docs/realtime.md

### AI

AI is off for writes by default: read-only tools only, write and destructive tools behind an env kill switch, an allowlist and a per-call approval; every tool call is logged; tests fake the agent. Details: https://github.com/qiick-io/app-ui/blob/develop/docs/ai-backend.md

### Operations

- `GET /health/live` (process up, no I/O) and `GET /health/ready` (database, cache and queue reachable, `503` otherwise), outside auth, from `routes/health.php`.
- Every Artisan command of the app is in the `<app>:` namespace (`<app>:install`, `<app>:upgrade`, `<app>:sync-permissions`, `<app>:create-user`).

## Testing

- Pest for PHP (`*Test.php`), Vitest or `node:test` for TypeScript / scripts. Test behaviour, not private structure.
- Every behaviour change adds or updates a test that fails without the change. Pure copy, styling or layout changes need none.
- Never delete, skip or weaken an existing test to get green; ask the maintainer first.
- No real network, AI provider or external process in tests: `Http::fake()`, `Process::fake()`, recorded fixtures, fake providers.
- Use model factories (and their states) for test data.
- UI flows that only a browser proves (navigation, dialogs, keyboard, focus) get a Pest browser test (`tests/Browser`, `pestphp/pest-plugin-browser`) where the repo has the plugin; check light and dark.
- `tests/Arch/NamingArchitectureTest.php` stays green. A new class satisfies its role suffix; scaffolding is listed in `tests/Arch/naming-architecture.php`, never renamed.
- Run the narrowest tests while iterating (`--filter`, a file path), and the repo's full quality gate (listed in its own section) before every push.

## Git, commits and pull requests

- **English everywhere in the repo:** code, comments, docs, commit messages, issues, pull requests.
- **Conventional Commits:** `type(scope): summary` in the imperative, lower case, no trailing period. Types: `feat`, `fix`, `refactor`, `perf`, `docs`, `test`, `chore`, `ci`, `build`, `security`. `!` or a `BREAKING CHANGE:` footer for breaking changes. commitlint checks PR titles in CI through the reusable `qiick-io/app-ui/.github/workflows/pr-title.yml` (check `pr-title / commitlint`), which every repo calls on `pull_request` with the `edited` type.
- **One issue, one branch, one pull request.** Branch from `develop` as `<type>/<issue>-<kebab-slug>` (`feat/42-project-registration`). Never commit directly to `develop` or `main`.
- Pull requests target `develop` and are **squash-merged**, so the PR title becomes the commit: make it a valid Conventional Commit. The body says what changed, why, and `Closes #<issue>`.
- Never force-push a shared branch, never `--no-verify`, never `--admin` or any branch-protection bypass.
- No attribution or `Co-authored-by` trailers for tools or agents.
- Never commit `.env`, secrets, tokens, personal phone numbers or email addresses; only `.env.example`.
- Ask the maintainer before: a new dependency, anything outside the issue's scope, repo settings (branch protection, required checks, secrets), paid services or accounts.

## Releases: never without the maintainer

`develop` is the integration branch. `main` holds releases only.

Agents may, when asked for a release:

- open a `develop` → `main` release pull request with a full body (changelog, included PRs), then **stop**.

Agents must not, unless the maintainer explicitly says so in this conversation:

- merge a release pull request or anything into `main` / `release/*`;
- bump versions, create tags (`vX.Y.Z` or any other), run `gh release create` or publish a GitHub Release;
- push to `main` / `master` / `release/*`;
- publish packages (Packagist, npm) or images (Docker Hub, GHCR);
- change repository visibility.

How a release goes, for reference: release PR → the maintainer merges it → tag `vX.Y.Z` on the merge commit → GitHub Release titled exactly with the tag, listing the included PRs → `main` is merged back into `develop`.

### Lifecycle (what the release PR prepares)

- **Versions:** Semantic Versioning. Pre-releases are `vX.Y.Z-beta.N`, then `vX.Y.Z-rc.N`, then `vX.Y.Z`; Composer packages declare them with `@beta` / `@RC` stability. Before `1.0.0` a minor may break; the notes say so.
- **`CHANGELOG.md`:** [Keep a Changelog](https://keepachangelog.com/en/1.1.0/): `[Unreleased]` collects `Added` / `Changed` / `Deprecated` / `Removed` / `Fixed` / `Security` as PRs land; the release PR moves it to `[X.Y.Z] - YYYY-MM-DD` with an **Upgrade notes** subsection (commands to run, config or env changes, breaking changes, data migrations that take long) and the compare link.
- **Versions in files:** `composer.json` / `package.json` `version`, the app's version constant or config, and the docs site version, bumped in the release PR only.
- **Required checks** on `develop` and `main` (the maintainer sets them; agents ask): the repo's own test, lint and type jobs, `pr-title / commitlint`, `app-ui-drift / standards`, and in apps `app-ui-drift / drift`, `ui-quality / ui-quality`, `migrations-frozen / migrations-frozen`, `distribution-smoke / smoke`.

## Automated checks

Machines enforce these in CI; keep them green, and never silence one to get green.

- **Dead code.** `knip` (unused files, exports, dependencies) runs in CI with the repo's committed `knip.json`. TypeScript has `noUnusedLocals` and `noUnusedParameters` on. Delete what is unused; prefix a parameter a signature forces on you with `_`. A knip `ignore*` entry needs a comment-worthy reason in the PR.
- **Strict models.** Each app's `AppServiceProvider::boot()` calls `Model::shouldBeStrict(! app()->isProduction())`: lazy loading, silently discarded attributes and missing attributes throw outside production. Fix the query (eager load, `$fillable`, select the column), never turn it off.
- **Larastan ratchet.** Each repo runs Larastan one level above where it started. Its `phpstan-baseline.neon` may only shrink: `scripts/qiick-ratchet.mjs phpstan` fails CI when the baseline holds more errors than on the base branch (only the PR that raises `level:` may baseline that level's errors). Never add to the baseline; fix the new error.
- **Theme tokens only.** In apps with the qiick UI, `scripts/check-ui-tokens.mjs` (synced by the pack, part of `npm run lint`) fails on arbitrary font sizes (`text-[11px]`) and on raw palette / `#hex` classes beyond `scripts/ui-token-baseline.txt`; the baseline only shrinks. The reusable `ui-quality.yml` runs it with the WCAG 2.2 AA contrast check of the installed theme and the app's axe smoke browser test (light, dark, high contrast).
- **Ratcheted code rules (ESLint repos).** `qiickRatchetedRules()` from `qiick-standards.eslint.js` turns prose rules into checks: only `lucide-react` icons and shadcn primitives (`no-restricted-imports` on other icon and component libraries), toasts only through `@/lib/toast` (not `sonner`), the clipboard only through `@/lib/clipboard` (`no-restricted-properties` on `navigator.clipboard`), booleans prefixed `is` / `has` / `can` / `should` (`@typescript-eslint/naming-convention`, type-aware; destructured contract names exempt), no single-letter names (`id-length`; `i`, `j`, `k`, `t`, `_` allowed). They run only in `npm run lint:standards` (`QIICK_RATCHET=1`), ratcheted per file and rule against `qiick-lint-baseline.json`: existing debt is listed, new findings fail, paid debt must be removed (`--update`).
- **Thin controllers.** The architecture test fails on a controller with more than five public methods of its own; controllers that already have more are listed with their count in `tests/Arch/naming-architecture.php` (`controllerPublicMethods`), and the test fails when a count grows or when it shrinks without the entry being lowered.
- **No hard-coded UI strings.** `i18next/no-literal-string` (JSX text only, not attributes such as `className` or `data-*`) flags visible text that bypasses translations: use `t('…')` with keys in every locale file. Existing debt is in `i18n-literal-baseline.json`; `scripts/qiick-ratchet.mjs lint` fails when a file's count grows, and when a count shrinks without the baseline being lowered (`--update`).

<!-- qiick-standards:end -->

# Repository rules: Excubia (`qiick-io/excubia-core`)

Rules for this repo only, on top of the qiick standards above. Where they are more specific, they win.

## Project

Excubia is the open-source (MIT) health check for Laravel apps in the qiick suite. Composer package `qiick/excubia-core`, CLI binary `bin/excubia`, plus the Artisan alias `php artisan excubia:check` through an auto-discovered service provider. It reads lockfiles and manifests and reports PHP / Laravel / Node end of life, known vulnerabilities, abandoned and stale packages, next-major blockers and an ordered upgrade plan.

- Source of truth: `docs/SPEC.md` and `docs/ARCHITECTURE.md`. Roadmap: `docs/ROADMAP.md`. Decisions D1–D13 and A1–A2 are final.
- Anything marked **[PROPOSAL]** or labelled `needs-decision` is not approved: do not build it.
- **Feature freeze until 1.0.0** (#233): from 1.0.0-beta.4 on, this package takes fixes only. No new check ids, runtime types, `excubia.json` keys, CLI or Artisan options, or report / push / runtime / exception schema fields; `docs/CONTRACTS.md` stays as beta.4 shipped it. New feature issues go to the v1.1.0 milestone. A contract change that turns out to be needed ships in a new beta or rc first (#88). See `docs/ROADMAP.md`.
- Opt-in runtime monitoring (Phase B, approved 2026-11-02) lives in this package, enabled via config, and reaches `excubia-app` only through the push protocol (`docs/SPEC.md` sections 12–13). There is no `excubia-probe`. The multi-project dashboard is the separate repo `qiick-io/excubia-app`, which consumes this package.
- Display name is "Excubia" alone, never "qiick Excubia", in user-facing strings and docs.

## Stack

- PHP `^8.2` package floor (installs in Laravel 12–13 apps; Laravel 11 dropped by D19); Pest and CI run on PHP 8.3+.
- `symfony/console`, `symfony/http-client`, `composer/semver`. No Guzzle, no Laravel runtime dependency (`illuminate/*` is only suggested).
- Dev: Pest 4, Pint (pinned `<1.31`), PHPStan / Larastan. No npm, no Vitest, no `php artisan test`.

## Layout

```
bin/excubia            standalone CLI entry, no Laravel boot
src/Checks/            one *Check per check id, implements HealthCheckContract
src/Contracts/         *Contract interfaces (HttpClientContract is faked in tests)
src/Data/              readonly *Data value objects
src/Enums/             *Enum (SeverityEnum, FindingStatusEnum, ReportFormatEnum, ExitCodeEnum)
src/Services/          *Service (runner, upgrade plan, snapshot, data-source API clients, cache)
src/Parsers/           *Parser for composer.lock/json, package-lock.json, yarn.lock, pnpm-lock.yaml (YAML subset), Node version files
src/Renderers/         table / json / markdown *Renderer
src/Registries/        HealthCheckRegistry (order, --only / --skip)
src/Configuration/     ExcubiaConfigurationLoader (excubia.json + excubia.schema.json)
src/Console/           symfony/console commands used by bin/excubia
src/Laravel/           service provider + Artisan alias
src/Exceptions/        *Exception
tests/Arch|Unit|Feature|Fixtures|Support   Pest; Arch: shared NamingArchitectureTest.php + PackageArchitectureTest.php
docs/                  SPEC, ARCHITECTURE, ROADMAP, report-schema.json
```

## Quality gate

```bash
composer test      # Pest
composer lint      # Pint --test
composer analyse   # PHPStan
php bin/excubia check --no-banner        # dogfood on this repo
```

Run all three before every push. CI (`.github/workflows/ci.yml`) runs PHP 8.3/8.4/8.5 × lowest/stable, Pint, PHPStan, Pest with coverage, a coverage gate (≥ 90 % on `src/Checks` and on all of `src/`) and a dogfood run.

## Excubia package rules

- This is a Composer package, not an app: no models, controllers, policies, jobs or actions. Its roles are `Check`, `Contract`, `Data` (readonly), `Enum`, `Service`, `Parser`, `Renderer`, `Registry`, `Loader`, `Command`, `Exception` (configured in `tests/Arch/naming-architecture.php`; package-only rules in `tests/Arch/PackageArchitectureTest.php`).
- `declare(strict_types=1);` in every PHP file. The package floor is PHP `^8.2`; Pest runs on PHP 8.3+, there is no PHP 8.2 Pest job.
- Tests: no real network (fake `HttpClientContract` + recorded responses in `tests/Fixtures/Responses/`).
- Privacy is a feature: only package names and versions leave the machine toward public data sources. The only other destination is the user's own `excubia-app`, through the opt-in push (`docs/SPEC.md` section 13). Never send file contents, absolute paths, hostnames or `.env` values. No telemetry to qiick or third parties.
- Report JSON shape (`docs/report-schema.json`), check ids and exit codes are public contracts: change them only when the issue says so, and update schema, README and CHANGELOG together.
- Releases also cover Packagist publishing. Claiming the Packagist vendor `qiick` and the trademark search before `v0.1.0` are human steps (`docs/SPEC.md` D5 and D13).

## Git and PR workflow

One issue → one branch → one PR. Never commit directly to `develop` or `main`.

1. Pick an open issue (or ask Luca to open one). Read its acceptance criteria.
2. `git checkout develop && git pull --ff-only`
3. `git checkout -b <type>/<issue>-<kebab-slug>` — types: feat, fix, refactor, chore, docs, test, perf, ci, build, security.
4. Commit with Conventional Commits in English (`feat(checks): add …`). Small, focused commits.
5. `composer lint && composer analyse && composer test`, then `git push -u origin HEAD`.
6. `gh pr create --base develop --title "<conventional title>" --body "Closes #<issue>…"` using `.github/pull_request_template.md`.
7. `gh pr merge --auto --squash` and move on to the next issue. Do not wait for CI; if it goes red, fix it in the same branch.
8. `git checkout develop && git pull --ff-only && git branch -d <branch>`.

`develop` is the integration branch (PR required, no review). `main` is releases only (1 review).

## Identity and security

- Commit and push only as **LucaGiardi20** (`git config user.name` = "Luca Giardi"). Check `gh api user -q .login` returns `LucaGiardi20` before any `gh` write.
- Never use the `lucagiarditailorventures` account, never add a `Co-authored-by` trailer for it, never run `gh auth switch`.
- Never use `--admin`, `--no-verify`, force-push to shared branches, or bypass branch protection.
- Never commit secrets, tokens, `.env` files, personal phone numbers or email addresses.

## Ask Luca first

Stop and ask before any of these:

- Releases: version bumps, CHANGELOG release sections, tags, GitHub Releases, `develop` → `main` merges, Packagist publishing (`.cursor/rules/no-auto-release.mdc`). Only when Luca says "rilascia".
- Adding, removing or upgrading a Composer dependency; changing the PHP floor.
- Changing public contracts (CLI options, exit codes, check ids, report JSON, `excubia.json` schema) beyond what the issue states.
- Anything outside SPEC/ARCHITECTURE scope, ambiguous acceptance criteria, or work that needs secrets, accounts or paid services.
- Editing branch protection, repo settings, CI required checks, or GitHub Actions secrets.

When blocked on one issue, leave a short comment on the issue explaining what is needed and move to the next.
