<!-- <laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Progress Tracking

The source of truth for implementation progress is `docs/plans/implementation-plan.md`.

Before starting implementation work, Codex must:

1. Read the implementation plan and relevant architecture, backend, frontend, and decision documents.
2. Inspect the existing code before deciding that a task is incomplete.
3. Continue from the next dependency-safe incomplete task.

When maintaining progress:

1. Change a completed task from `[ ]` to `[x]` only after verification.
2. Keep partially completed tasks as `[ ]` and add a concise progress note.
3. Add newly discovered work to the appropriate dependency-ordered phase.
4. Do not rely on previous conversation history; inspect the repository and plan in every new session.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

Use the architecture skills as follows:

- Activate `senior-architect` before creating or changing cross-module architecture, system boundaries, data ownership, runtime topology, major technology choices, or consequential architectural decisions. Read the existing architecture and ADRs first; preserve accepted decisions and record proposed changes in `docs/decisions/` rather than changing them silently.
- Activate `senior-backend` when designing or changing backend contracts, APIs, domain actions, authorization, data models, migrations, queues, idempotency, observability, or operational behavior. Follow `docs/backend/BACKEND_DESIGN.md` and the accepted architecture.
- Activate `frontend-design` when creating a new product workflow, page family, scheduling interaction, visual system, or significant UI restructure. Follow `docs/frontend/FRONTEND_ARCHITECTURE.md`, preserve existing accessible components, and align every UI state with backend permissions, capabilities, and errors.
- For cross-layer features, use the skills in this order: `senior-architect` for boundaries/decisions, `senior-backend` for contracts/invariants, then `frontend-design` for interactions/presentation. Review `docs/architecture/CROSS_LAYER_REVIEW.md` before implementation.
- Do not activate `frontend-design` for trivial copy edits or isolated mechanical fixes that do not change interaction or visual design.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines> -->
```md
<laravel-boost-guidelines>
=== project workflow rules ===

# TalaSched Development Workflow

## Source of Truth

The source of truth for implementation progress is:

`docs/plans/implementation-plan.md`

The source of truth for architecture and design decisions is contained in:

- `docs/architecture/`
- `docs/backend/`
- `docs/frontend/`
- `docs/decisions/`

Before starting implementation work, Codex must:

1. Read `docs/plans/implementation-plan.md`.
2. Identify the requested or earliest genuinely dependency-safe incomplete task.
3. Read only the architecture, backend, frontend, ADR, and rule documents relevant to that task.
4. Inspect the existing implementation before deciding that work is missing.
5. Follow accepted architecture and ADRs rather than redesigning settled decisions.
6. Work on one narrowly scoped implementation-plan item at a time unless that item explicitly requires coordinated cross-layer work.

Do not rely on previous Codex conversation history to determine project state.

Inspect the repository and implementation plan in every new session.

## Progress Tracking

When maintaining implementation progress:

1. Change `[ ]` to `[x]` only after the narrowly stated task is fully implemented and verified.
2. Keep partially completed tasks as `[ ]`.
3. Add a concise `Progress:` note when meaningful partial implementation exists.
4. Add newly discovered work to the earliest appropriate dependency-safe phase.
5. Do not mark infrastructure complete merely because a local development fallback exists.
6. Do not remove unfinished work simply to allow later phases to proceed.
7. Update `docs/plans/implementation-plan.md` after completing or materially advancing the requested task.

## Dependency-Safe Progression

Phase numbers describe architectural and product sequencing, but they are not absolute execution gates.

An incomplete task in an earlier phase blocks later work only when the later task actually depends on it for:

- correctness;
- security;
- tenant isolation;
- authorization;
- data integrity;
- a documented architectural invariant;
- a required runtime capability.

Do not require an entire phase to reach 100% completion before beginning later dependency-safe work.

Production infrastructure and operational hardening may remain incomplete while local product development continues when suitable development infrastructure already exists.

Examples of potentially non-blocking infrastructure work include:

- production Redis provisioning while Laravel database-backed cache, sessions, queues, and locks are sufficient for local development;
- production S3-compatible storage before a feature actually requires private object storage;
- production PostgreSQL role separation;
- production TLS verification;
- PostgreSQL CI environment verification;
- deployment-specific monitoring or operational infrastructure.

These tasks must remain `[ ]` until actually completed.

Do not falsely mark deferred infrastructure as complete.

When selecting the next task:

1. Prefer security-critical and data-integrity foundations that later work depends on.
2. Prefer tenant isolation and authorization foundations before exposing affected features.
3. Skip earlier incomplete infrastructure tasks when they have no concrete dependency on the requested local product work.
4. Continue into later phases when their actual dependencies are satisfied.
5. Record deferred work accurately rather than implementing it prematurely.

## Local Development Infrastructure

Local development infrastructure may differ from the final production topology when the architecture explicitly permits it.

PostgreSQL is the development database when configured for the project.

Redis is not required merely because it is a production target.

When configured, Laravel database-backed infrastructure may be used locally for:

- cache;
- cache locks;
- sessions;
- queues;
- failed jobs;
- job batches.

Do not install, provision, or migrate to Redis unless:

1. the requested task explicitly requires Redis behavior;
2. the existing development fallback cannot satisfy the required invariant; or
3. the user explicitly requests Redis setup.

Likewise, do not provision production object storage, TLS, database roles, CI infrastructure, or deployment services merely because they appear as future implementation-plan tasks.

Implement infrastructure when its dependency becomes real.

## Feature UI Progression

Frontend development is NOT deferred until Phase 11.

Frontend work belongs to the phase that owns the corresponding product capability.

Examples:

- Phase 2 owns role and member administration UI.
- Phase 3 owns academic structure, calendar, period, and student-group administration UI.
- Phase 4 owns faculty, room, feature, subject, offering, and availability UI.
- Phase 5 owns timetable grid, filters, entry editing, conflict presentation, and accessible schedule alternatives.
- Phase 6 owns timetable versioning, comparison, publication, and rollback UI.
- Phase 7 owns workflow design, approval inboxes, decision forms, timelines, and signatory administration.
- Phase 8 owns plan, usage, subscription, entitlement, and upgrade UI.
- Phase 10 owns Excel upload, mapping, preview, validation, and template-management UI.

Phase 11 is primarily an application-wide UX completion and hardening phase.

It owns work such as:

- application navigation;
- consistency;
- shared states;
- responsive behavior;
- accessibility;
- missing workflow coverage;
- browser journey coverage.

It is not the starting point for frontend development.

When a feature's backend contracts and invariants are stable enough, implement its corresponding frontend workflow in the owning phase.

Do not postpone a dependency-safe frontend implementation merely because later frontend phases exist.

## Cross-Layer Feature Development

For features involving both backend and frontend:

1. Read `docs/architecture/CROSS_LAYER_REVIEW.md`.
2. Establish the smallest backend contract required by the feature.
3. Implement server-side authorization, tenant isolation, validation, and capability enforcement.
4. Implement the corresponding Inertia/Vue interaction against that contract.
5. Handle loading, empty, validation, error, permission, entitlement, responsive, and accessibility states where applicable.
6. Add focused backend and frontend verification.
7. Update the implementation plan.
8. Stop when the requested implementation-plan item is complete.

Frontend restrictions must never be treated as security controls.

The frontend must not invent authorization, subscription, tenant, or validation rules.

If frontend implementation exposes a missing backend capability, implement or propose that capability in the owning backend layer instead of creating a client-side workaround.

## Agent Efficiency

Minimize unnecessary context consumption and unrelated work.

### Scope

- Work on ONE implementation-plan checkbox at a time.
- Do not automatically continue to the next checkbox after completing the requested task.
- Do not attempt to finish an entire phase unless explicitly requested.
- Do not perform unrelated refactors.
- Do not perform speculative cleanup.
- Do not implement future requirements opportunistically.
- Do not redesign accepted architecture.
- Do not revisit accepted ADR decisions without a concrete conflict.
- Do not create abstractions solely for hypothetical future use.

### Repository Inspection

Start with the smallest relevant repository scope.

Inspect only the files and directories likely to affect the current task.

Expand repository inspection only when a concrete dependency requires it.

Do not repeatedly inspect unrelated areas of the repository.

### Documentation

Read only documentation relevant to the current task.

Do not repeatedly read the entire documentation tree.

Always read:

- the relevant implementation-plan section;
- applicable architecture/backend/frontend documents;
- applicable ADRs;
- applicable `.ai/rules`.

Read additional documentation only when implementation reveals a concrete dependency.

### Testing

Run the narrowest meaningful verification first.

Preferred sequence:

1. affected test or test filter;
2. closely related tests;
3. affected frontend type/lint/build checks when applicable;
4. broader regression suite only when justified by the scope of the change.

Do not begin every task by running the entire test suite.

Run the full suite after focused verification when:

- the change affects shared infrastructure;
- the change affects cross-cutting behavior;
- the implementation-plan acceptance criteria require it;
- or broad regression confidence is reasonably necessary.

### Completion

Once the requested task is implemented and verified:

1. update its implementation-plan status;
2. record an accurate Progress note if only partially complete;
3. summarize what changed;
4. summarize focused verification;
5. identify any genuine blocker;
6. STOP.

Do not automatically begin another implementation-plan item.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4.

You are an expert with the Laravel ecosystem.

Always use APIs matching the installed major version of each package.

Do not assume a package version.

Before relying on a package API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JavaScript packages: inspect `package.json`.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`.

You MUST activate the relevant skill whenever working in that domain.

Do not wait until you are stuck.

### Architecture

Activate `senior-architect` before:

- creating or changing cross-module architecture;
- changing system boundaries;
- changing data ownership;
- changing runtime topology;
- introducing major technology choices;
- making consequential architectural decisions.

Read the existing architecture and ADRs first.

Preserve accepted decisions.

When a genuine architectural change is necessary, propose and document it rather than changing architecture silently.

### Backend

Activate `senior-backend` when designing or changing:

- backend contracts;
- APIs;
- domain actions;
- authorization;
- data models;
- migrations;
- queues;
- idempotency;
- observability;
- operational behavior.

Follow:

`docs/backend/BACKEND_DESIGN.md`

and the accepted system architecture.

### Frontend

Activate `frontend-design` when creating or significantly changing:

- product workflows;
- page families;
- scheduling interactions;
- visual systems;
- significant UI structure.

Follow:

`docs/frontend/FRONTEND_ARCHITECTURE.md`

Preserve existing accessible components.

Align UI states with backend:

- permissions;
- capabilities;
- validation;
- structured errors.

Do not activate `frontend-design` for trivial copy edits or isolated mechanical changes that do not alter interaction or visual design.

### Inertia + Vue

Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

### Cross-Layer Features

For cross-layer features, use skills in this order when applicable:

1. `senior-architect` — only when boundaries or architectural decisions actually need review/change;
2. `senior-backend` — contracts and invariants;
3. `frontend-design` — interactions and presentation;
4. `inertia-vue-development` — Inertia/Vue implementation patterns.

Do not activate `senior-architect` merely because a feature touches both frontend and backend when the architecture is already settled.

Review `docs/architecture/CROSS_LAYER_REVIEW.md` before implementing significant cross-layer workflows.

## Conventions

- Follow existing code conventions.
- Before creating or editing a file, inspect sibling files for structure, naming, and patterns.
- Use descriptive variable and method names.
- Prefer `isRegisteredForDiscounts` over ambiguous names such as `discount()`.
- Check for reusable existing components before creating new ones.
- Preserve existing abstractions when they satisfy the requirement.
- Do not introduce duplicate patterns unnecessarily.

## Verification Scripts

Do not create verification scripts or use Tinker when automated tests already cover the behavior.

Unit and feature tests are preferred.

## Application Structure & Architecture

- Stick to the existing directory structure.
- Do not create new base folders without approval.
- Do not change application dependencies without approval.
- Do not introduce architecture conflicting with documented decisions.

## Frontend Bundling

If frontend changes are implemented but not visible, check whether the development/build process is running.

The relevant commands may include:

`npm run dev`

`npm run build`

`composer run dev`

Do not assume missing UI means the implementation failed before checking the frontend build/runtime state.

## Documentation Files

Do not create new documentation files unless explicitly requested.

Updating existing documentation required by the implementation plan is allowed.

## Replies

Be concise.

Focus on:

- what changed;
- why it changed when non-obvious;
- tests/checks performed;
- blockers or remaining work.

Avoid explaining obvious implementation details.

=== boost rules ===

# Laravel Boost

## Tools

Laravel Boost is an MCP server with tools designed specifically for this application.

Prefer Boost tools over manual alternatives when an appropriate Boost tool exists.

Use `database-query` for read-only database queries instead of raw SQL through Tinker.

Use `database-schema` to inspect table structure before writing migrations or models.

Use `get-absolute-url` to resolve the project's correct scheme, domain, and port before sharing project URLs.

Use `browser-logs` for recent browser errors, exceptions, and logs.

Ignore stale browser logs.

## Searching Documentation

Always use `search-docs` before making framework/package-dependent code changes.

Do not skip this step.

It returns version-specific documentation based on installed packages.

Pass a `packages` array when the relevant packages are known.

Use multiple broad topic queries.

Examples:

`['rate limiting', 'routing rate limiting', 'routing']`

Do not include package names in search terms when package metadata is already supplied.

Prefer:

`test resource table`

instead of:

`filament 4 test resource table`

### Search Syntax

1. Words use auto-stemmed AND logic.
2. Quoted phrases require exact adjacency.
3. Words and quoted phrases may be combined.
4. Multiple queries provide OR-style exploration.

## Project Rules

This project may contain committed area-grouped rules under:

`.ai/rules`

These rules contain settled decisions, non-obvious traps, standing constraints, and path-specific framework/package guidance.

Before entering plan mode or creating/editing files:

1. Open `.ai/rules/index.md` if `.ai/rules` exists.
2. Read every rule file whose glob covers the paths in scope.
3. Run a targeted keyword search through `.ai/rules` for the current domain to catch relevant rules not discovered solely by path matching.
4. Follow every applicable rule before writing code.

Do not perform broad keyword searches unrelated to the current task.

If `.ai/rules` does not exist, continue without it.

Record genuinely durable project rules with `record-rule` when appropriate.

Use a relevant glob, short title, and concise note.

Do not use personal/session memory as a replacement for repository-persisted project rules.

## Artisan

Run Artisan commands directly through the command line.

Examples:

`php artisan route:list`

Use:

`php artisan list`

to discover available commands.

Use:

`php artisan [command] --help`

to inspect parameters.

Inspect routes with:

`php artisan route:list`

Useful filters include:

- `--method=GET`
- `--name=users`
- `--path=api`
- `--except-vendor`
- `--only-vendor`

Read configuration using:

`php artisan config:show app.name`

`php artisan config:show database.default`

or inspect the relevant file under `config/`.

## Tinker

Use Tinker only when it is genuinely useful for debugging or inspecting application behavior not better covered by existing tools/tests.

Do not create models through Tinker without user approval.

Prefer tests with factories.

Always use single quotes around Tinker execution code to prevent shell expansion.

Example:

`php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, including single-line bodies.
- Use PHP 8 constructor property promotion where appropriate.
- Do not leave empty zero-parameter constructors unless the constructor is private.
- Use explicit return types.
- Use parameter type hints.
- Use TitleCase enum keys such as `FavoritePerson`, `BestLake`, and `Monthly`.
- Prefer PHPDoc blocks over inline comments.
- Add inline comments only when logic is exceptionally difficult to understand.
- Use array-shape PHPDoc definitions where useful.

=== deployments rules ===

# Deployment

Laravel may be deployed using Laravel Cloud when selected for this project.

Do not introduce or configure a deployment provider unless requested or required by the implementation plan's active deployment work.

=== herd rules ===

# Laravel Herd

The application is served by Laravel Herd at:

`https?://[kebab-case-project-dir].test`

Use `get-absolute-url` to generate valid project URLs.

Do not run commands to manually serve the site.

Use the `herd` CLI to manage services, PHP versions, and sites when necessary.

Examples:

`herd sites`

`herd services:start <service>`

`herd php:list`

Use:

`herd list`

to discover available commands.

=== tests rules ===

# Test Enforcement

Every behavior change must be programmatically tested.

Write a new test or update an existing test when behavior changes.

Run the minimum number of tests needed to verify the requested change efficiently.

Prefer:

`php artisan test --compact <specific-test-file>`

or:

`php artisan test --compact --filter=<testName>`

Do not automatically run the entire test suite before focused tests.

Broader regression testing should follow successful focused verification when justified by the change.

=== inertia-laravel/core rules ===

# Inertia

Inertia provides client-side SPA behavior while preserving server-side application patterns.

Components normally live under:

`resources/js/pages`

unless configured differently by the project.

Use `Inertia::render()` for server-side routing instead of Blade views for Inertia application surfaces.

Always use `search-docs` for version-specific Inertia documentation.

Activate `inertia-vue-development` when working with Inertia/Vue client-side patterns.

# Inertia v3

Use APIs appropriate for the installed Inertia version.

Relevant Inertia v3 functionality includes:

- standalone HTTP requests through `useHttp`;
- optimistic updates with rollback;
- layout props through `useLayoutProps`;
- instant visits;
- simplified SSR through `@inertiajs/vite`;
- custom exception handling.

Features carried from v2 include:

- deferred props;
- infinite scroll;
- merged props;
- polling;
- prefetching;
- once props;
- flash data.

When using deferred props, provide an appropriate loading/skeleton state.

Axios is not assumed.

Use the built-in client unless an approved dependency explicitly provides another approach.

`Inertia::lazy()` / `LazyProp` has been removed.

Use `Inertia::optional()` where appropriate.

Nested prop types support dot-notation paths.

SSR works through the configured Vite integration.

Use current event names and router APIs for the installed version.

Do not rely on removed legacy APIs.

=== laravel/core rules ===

# Do Things the Laravel Way

Use Laravel generators when creating framework-managed files.

Examples include:

`php artisan make:model`

`php artisan make:controller`

`php artisan make:migration`

`php artisan make:test`

Use:

`php artisan list`

and:

`php artisan [command] --help`

when command options are uncertain.

Pass `--no-interaction` to Artisan generators and commands that could request user input.

## Model Creation

When creating models, provide useful factories when required by the project.

Add seeders only when the implementation actually requires canonical or development seed data.

Do not create unnecessary seeders solely because a model was created.

## APIs & Eloquent Resources

For APIs, prefer Eloquent API Resources and the project's existing API conventions.

Do not introduce API versioning or a new API structure when it conflicts with the established project contract.

## URL Generation

Prefer named routes and `route()` for server-generated links.

For frontend route access, follow the project's Wayfinder conventions.

## Testing

Use factories when creating models in tests.

Check existing factory states before manually constructing model graphs.

Follow the project's existing Faker convention.

Create Pest tests with Laravel generators when appropriate.

Most application behavior should be covered by feature tests.

## Vite Errors

If Laravel reports that a frontend asset cannot be found in the Vite manifest, verify the frontend build/runtime.

Relevant commands may include:

`npm run build`

`npm run dev`

`composer run dev`

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes.

Import generated controller actions from:

`@/actions/`

Import named routes from:

`@/routes/`

Do not hand-write URLs when a generated Wayfinder route/action exists.

Regenerate Wayfinder artifacts whenever backend route changes require it.

=== pint/core rules ===

# Laravel Pint

If PHP files were modified, run:

`vendor/bin/pint --dirty --format agent`

before finalizing the task.

Do not use:

`vendor/bin/pint --test --format agent`

for the normal agent formatting workflow.

=== pest/core rules ===

# Pest

This project uses Pest.

Create Pest tests using:

`php artisan make:test --pest {name}`

The `{name}` argument should not contain the test-suite directory.

Use:

`php artisan make:test --pest SomeFeatureTest`

instead of:

`php artisan make:test --pest Feature/SomeFeatureTest`

Run tests with:

`php artisan test --compact`

or narrow them using a filename/filter.

Do not delete tests without approval.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.

Activate `inertia-vue-development` when working with Inertia/Vue client-side patterns.

Follow existing project component, TypeScript, accessibility, styling, and Wayfinder conventions.

Before creating a new shared component, check whether an appropriate reusable component already exists.

For user-facing workflows, consider:

- loading states;
- empty states;
- validation states;
- error states;
- permission states;
- entitlement/subscription states;
- responsive behavior;
- keyboard interaction;
- accessibility.

Do not duplicate server-side security rules in a way that makes the frontend appear authoritative.

Frontend permission/capability state exists for presentation and interaction; the backend remains authoritative.

</laravel-boost-guidelines>
```