# AGENTS.md

## Purpose

This repository is a Laravel application for a personal Amazon Selling Partner tool.

It integrates with:

- Amazon Selling Partner API (SP-API) using the **official Amazon SP-API SDK**
- Amazon Ads API using the approved integration approach in this codebase

All generated, edited, refactored, or suggested code must follow the rules in this file.

---

## Core Priorities

When working in this repository, optimize for:

- correctness
- maintainability
- Laravel conventions
- explicit, testable code
- safe incremental changes
- compatibility with the official Amazon SDKs and APIs
- clear separation of concerns

Do not optimize for cleverness, abstraction for its own sake, or shortcut-heavy code.

---

## Non-Negotiable Package Rules

### Selling Partner API

- Use the **official Amazon SP-API SDK** for all Selling Partner API integrations.
- Do **not** use `jlevers/selling-partner-api` for any new code.
- Do **not** suggest, add, install, import, reference, wrap, or reintroduce `jlevers/selling-partner-api`.
- Do **not** refactor official SDK code toward `jlevers/selling-partner-api` patterns.
- If legacy code still uses `jlevers/selling-partner-api`, treat it as deprecated and isolated unless explicitly asked to migrate or maintain it.

### Amazon Ads API

- Use the project-approved Ads API integration approach already adopted in this repository.
- Keep Ads API logic separate from SP-API logic unless orchestration at the business layer genuinely requires both.

### Forbidden

Do not generate or propose:

- `jlevers/selling-partner-api`
- legacy wrapper-based SP-API patterns
- code that assumes old SDK method signatures or behaviors
- broad rewrites just to “standardize” around outdated abstractions

---

## Framework and Stack Assumptions

This project is built with Laravel and should follow normal Laravel and PHP best practices.

Assume the following unless explicitly told otherwise:

- PSR-12 formatting
- constructor dependency injection
- thin controllers
- service-oriented integration code
- queued jobs for long-running or retryable work
- config-driven behavior
- environment-driven credentials
- explicit validation and error handling
- unit and feature tests for important paths

---

## Project Structure Preferences

Prefer the following structure when adding or moving code:

- `app/Services/Amazon/SPAPI/` for Selling Partner API services
- `app/Services/Amazon/Ads/` for Ads API services
- `app/Jobs/Amazon/` for queued Amazon jobs
- `app/Data/Amazon/` or `app/DTOs/Amazon/` for request/response DTOs
- `app/Exceptions/Amazon/` for Amazon-specific exceptions
- `app/Support/Amazon/` for factories, resolvers, helpers, and support classes
- `config/amazon.php` for Amazon-related configuration
- `tests/Unit/Amazon/` for isolated service and mapping tests
- `tests/Feature/Amazon/` for orchestrated integration flow tests

Do not place meaningful Amazon integration logic inside:

- controllers
- routes
- Blade views
- traits used as dumping grounds
- random helper files
- console commands beyond orchestration

---

## Amazon Configuration Rules

- Store Amazon credentials, IDs, endpoints, and other environment-specific values in `.env`.
- Expose and organize them through `config/amazon.php` or clearly scoped config files.
- Do **not** call `env()` directly in application code outside config files.
- Application code must read configuration via `config(...)`, not `env(...)`.
- Keep SP-API and Ads API configuration clearly separated within config.
- Fail fast when required configuration is missing.
- Do not hardcode credentials, regions, marketplace IDs, profile IDs, endpoints, or account identifiers in application code.

### Preferred Pattern

Use:

- `.env` for raw values
- `config/amazon.php` for structure and defaults
- `config('amazon.sp_api.region')` style access in application code

Do not scatter raw `env('...')` calls across services, jobs, controllers, or commands.

---

## Architecture Rules

Keep these concerns separate:

- credentials and token handling
- client creation
- request construction
- transport/API execution
- response mapping
- persistence
- business rules
- retry/throttling logic
- logging and observability

### Service Boundaries

Prefer focused classes with clear responsibilities.

Good examples of intent:

- `AmazonSpApiClientFactory`
- `AmazonAdsClientFactory`
- `AmazonCredentialProvider`
- `AmazonRegionResolver`
- `AmazonMarketplaceResolver`
- `AmazonAdsProfileResolver`
- `OrdersService`
- `ListingsService`
- `ReportsService`
- `CampaignService`
- `AdsReportService`

Avoid giant “do everything” service classes like:

- `AmazonService`
- `AmazonApiManager`
- `MarketplaceHelper` containing unrelated behavior

---

## Laravel Best Practices

### Required

- Follow PSR-12 and normal Laravel naming conventions.
- Use constructor injection.
- Use typed properties, parameter types, and return types where practical.
- Keep controllers thin and focused on HTTP concerns.
- Put business logic into services, actions, or well-scoped domain classes.
- Use jobs for slow, polling, or retryable work.
- Use Form Requests or validators for incoming data validation.
- Use config files for app behavior and environment mapping.
- Use migrations, seeders, caches, queues, and events in Laravel-standard ways.
- Prefer explicit classes over hidden magic.

### Avoid

- fat controllers
- static helper sprawl
- unstructured arrays everywhere for complex data
- hidden state
- service locator style code
- facades everywhere in core domain logic when injection would be clearer
- broad catch-all exception swallowing
- business logic in commands/controllers/views

---

## Data and DTO Rules

When request and response payloads become non-trivial, prefer DTOs or value objects over loose nested arrays.

Use DTOs especially for:

- report parameters
- feed submission payloads
- listing update requests
- Ads report requests
- parsed document metadata
- normalized Amazon response payloads

DTOs should be small, explicit, and typed where sensible.

Do not build sprawling anonymous array structures if a named data object would make the code clearer.

---

## Client Construction Rules

- Centralize client creation.
- Do not instantiate Amazon SDK clients inline across the codebase.
- Client construction must be deterministic, testable, and easy to replace in tests.
- Centralize auth/signing/token handling logic.
- Do not duplicate authentication setup in multiple service classes.

Prefer factories, resolvers, and providers instead of copy-pasted client bootstrap code.

---

## Amazon API Best Practices

Amazon APIs are rate-limited, context-sensitive, and delightfully capable of causing chaos when treated casually.

### Required

- Respect rate limits and throttling guidance.
- Implement retry handling with exponential backoff for transient failures.
- Handle token refresh and token expiry explicitly.
- Handle pagination correctly.
- Preserve context such as region, marketplace, seller account, and Ads profile.
- Design retryable workflows to be idempotent where possible.
- Use meaningful exceptions instead of silent failure.
- Log enough context to debug failures safely.
- Never log secrets, raw tokens, or sensitive authorization material.

### Prefer

- explicit error handling
- focused service methods
- resilient sync design
- replay-safe jobs
- clear mapping from Amazon payloads into application models

### Avoid

- hidden retries
- silent null returns for exceptional conditions
- ignoring throttling behavior
- assuming a single marketplace/profile/region unless explicitly configured that way

---

## SP-API Specific Rules

For Selling Partner API work:

- use the official SDK’s request and response models as intended
- keep services focused by API concern
- separate report creation, report polling, document retrieval, decompression, parsing, and persistence into distinct steps when complexity requires it
- treat sync operations as retryable and idempotent where possible
- validate marketplace and region context before making requests
- keep catalog, listings, orders, inventory, reports, feeds, and finances concerns separated unless business logic requires orchestration

Do not create fake “generic Amazon request wrappers” that erase the SDK’s real behavior unless there is a very clear benefit.

---

## Ads API Specific Rules

For Amazon Ads API work:

- centralize Ads authentication and profile selection
- require explicit Ads profile or account context before making requests
- separate campaign management, reporting, and metadata lookup into focused services
- avoid mixing Ads concerns into SP-API classes
- keep reporting pipelines resilient and retry-safe
- validate date ranges, profile IDs, and account scope before issuing requests

Do not assume Ads API and SP-API identities or contexts are interchangeable.

---

## Queues, Jobs, and Long-Running Work

Use queued jobs for:

- report generation and polling
- feed submission and status polling
- bulk sync operations
- backfills
- retryable imports
- throttled API work
- Ads reporting workflows

### Job Rules

- Jobs should be idempotent where possible.
- Jobs should carry enough context to resume safely.
- Jobs must not depend on request or session state.
- Jobs should distinguish retryable failures from terminal failures.
- Jobs should persist progress or checkpoints when useful.
- Jobs should not hide failures behind generic logging.

Do not leave long-running Amazon API workflows inside controllers.

---

## Error Handling and Exceptions

### Required

- Throw meaningful domain-specific exceptions for Amazon-related failures.
- Preserve root cause information.
- Log structured context such as operation name, marketplace, region, seller/account/profile, and identifiers relevant to debugging.
- Redact secrets and tokens.
- Fail explicitly when required configuration or context is missing.

### Avoid

- `catch (\Exception $e) {}` with vague logging
- silent returns on error
- generic “something went wrong” handling deep in service code
- suppressing Amazon errors that matter to business logic

---

## Logging and Observability

When logging Amazon-related activity:

- include operation name
- include seller/account/profile context where relevant
- include marketplace and region where relevant
- include report/feed identifiers where useful
- include enough detail to diagnose throttling, auth issues, invalid requests, and retry behavior

Do not log:

- access tokens
- refresh tokens
- secrets
- raw authorization headers
- sensitive user/account data unless explicitly needed and safely handled

Prefer structured logs over string soup.

---

## Database and Persistence Rules

- Keep persistence separate from raw API transport.
- Map Amazon responses into application-level structures before persisting when complexity warrants it.
- Use transactions when multiple writes must succeed together.
- Design sync/import workflows so repeated execution does not corrupt data.
- Prefer explicit upsert or reconciliation strategies for imported data.
- Avoid tightly coupling database writes to raw response shapes when a normalization layer would reduce fragility.

---

## Legacy SP-API Migration Workflow
When asked to audit legacy usage, perform a discovery-only pass unless explicitly instructed to modify code.

This project is actively migrating away from `jlevers/selling-partner-api` to the official Amazon SP-API SDK.

- Do not introduce any new usage of `jlevers/selling-partner-api`.
- When legacy usage is encountered, record it in `docs/jlevers-migration-checklist.md`.
- When legacy usage is replaced, update the checklist entry status to `migrated`.
- If a file contains legacy usage but migration is outside the current task, record or update the item instead of ignoring it.
- Prefer incremental, low-risk migration steps over broad rewrites.
- Do not remove the legacy package from dependencies until all relevant checklist items are migrated or explicitly deferred.

### Checklist Entry Requirements

Each checklist entry should include:

- file path
- class or module name
- legacy usage type
- purpose
- official SDK replacement target
- status
- notes

### Allowed Status Values

- discovered
- planned
- in_progress
- migrated
- blocked
- deferred

### Migration Rules

- When editing a file that contains legacy `jlevers/selling-partner-api` usage, first check whether that usage should be migrated as part of the current change.
- If it is not being migrated in the current task, add or update a checklist entry rather than ignoring it.
- If it is migrated, mark the entry as `migrated` and briefly note what replaced it.
- Do not mark an item as `migrated` unless the legacy dependency has actually been removed from that area of code.
- Do not expand the legacy approach while touching nearby code.
- Prefer safe, reviewable migration steps.

---

## Migration and Refactor Rules

When modifying existing Amazon integration code:

- preserve or improve alignment with the official Amazon SDK approach
- do not normalize code back toward `jlevers/selling-partner-api`
- prefer safe, incremental refactors over wide rewrites
- keep new code on the official SDK path even if nearby legacy code exists
- isolate legacy package usage rather than expanding it
- only perform broad architecture changes when explicitly asked

If you encounter both legacy and official SDK code in the same area:

- do not expand the legacy approach
- do not duplicate patterns from the legacy package
- prefer adapter boundaries only when needed to support staged migration

---

## Testing Expectations

All critical Amazon logic should be test-covered.

### Required

- Unit test services, DTO mapping, and transformation logic.
- Feature test major workflows where practical.
- Mock or fake API clients cleanly.
- Test failure paths.
- Test retry logic where relevant.
- Test pagination behavior where relevant.
- Test idempotent behavior for repeated sync runs.
- Test token expiry/refresh behavior where applicable.
- Test config-driven logic with overridden config values.

### Avoid

- brittle tests coupled to live Amazon responses
- tests that depend on external network access
- untestable service classes with hidden state
- deeply integrated code that cannot be mocked or replaced

---

## Code Style Preferences

Prefer:

- small focused classes
- explicit names
- readable methods
- straightforward control flow
- clearly named DTOs and exceptions
- constructor injection
- config-driven behavior
- narrow interfaces where useful
- comments only when they add real clarity

Avoid:

- needless abstraction layers
- giant god classes
- overuse of traits
- deeply nested conditionals when a clearer structure is possible
- magic arrays with unclear keys
- comment-heavy code that explains obvious syntax instead of intent

---

## JSON Viewer UI Standard

For Blade JSON viewer blocks (for example debug payload viewers in `sqs_messages` and `orders` views), use the same readable formatting pattern unless a page has a strong reason to differ:

- render a collapsible tree view plus a raw JSON fallback block
- allow clicking primitive values to copy both JSON path and value for prompts/logs/docs
- use a monospace font at readable size (around `14px`) and comfortable line height
- use a high-contrast dark container with clear token colors for keys, strings, numbers, booleans, and null
- support horizontal scrolling for large payloads and visible nesting structure
- show lightweight copy feedback (for example "Copied {path}") near the viewer
- avoid `innerHTML` for dynamic JSON keys/values; build DOM nodes with text content

If adding a new JSON viewer, match the existing style used in:

- `resources/views/sqs_messages/show.blade.php`
- `resources/views/orders/show.blade.php`

---

## Change Management Rules

When making changes:

- make the smallest sensible change that solves the real problem
- preserve existing behavior unless the task explicitly asks to change it
- avoid unrelated refactors
- avoid speculative architecture work
- do not rename or reorganize broadly without reason
- keep diffs easy to review
- respect existing patterns if they are sound and not in conflict with this file

---

## What to Do Before Writing Amazon Code

Before implementing or modifying Amazon-related functionality, assume all of the following:

1. SP-API must use the official Amazon SP-API SDK only.
2. `jlevers/selling-partner-api` must not be used for new work.
3. Ads API work must follow the project’s approved Ads integration pattern.
4. Laravel conventions take priority unless there is a strong explicit reason otherwise.
5. Config must come from `config(...)`, not direct `env(...)` calls in app code.
6. Long-running or retryable work belongs in jobs.
7. Code must be testable, explicit, and maintainable.

---

## What Not to Generate

Do not generate:

- code using `jlevers/selling-partner-api`
- direct `env()` calls in services/controllers/jobs
- fat controllers containing Amazon API logic
- giant generic `AmazonService` classes
- hardcoded marketplace IDs, profile IDs, credentials, or endpoints
- hidden retry logic that obscures failures
- broad rewrites unless explicitly requested
- convenience abstractions that make official SDK behavior harder to understand

---

## Preferred Decision Pattern

When unsure, choose the option that is:

- more explicit
- more Laravel-native
- easier to test
- easier to review
- less coupled
- aligned with the official Amazon SDKs
- safer to maintain over time

Clarity beats cleverness.
