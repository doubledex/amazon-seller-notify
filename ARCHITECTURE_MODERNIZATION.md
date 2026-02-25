# Architecture Modernization Plan

## Why this document
This project started as a pragmatic DIY app and has grown quickly with AI-assisted iteration. The next phase should reduce risk by aligning with Amazon's current direction:

- official SDK usage over custom/legacy request patterns,
- async/report-first workflows where Amazon expects them,
- resilient polling/queue patterns,
- cost-aware API usage under new charging constraints.

This document proposes a target architecture and a migration path that can be implemented incrementally.

---

## Current shape (high-level)
The app already has a healthy Laravel service + command layout:

- `app/Services/*` for domains (orders, listings, ads, metrics, SQS),
- scheduled commands in `routes/console.php`,
- async report queue/poll flow already used for listings + ads,
- SP-API integration through `selling-partner-api` package.

This is a strong base; modernization is mostly about hardening boundaries, standardizing API access, and making deprecation/cost changes easy to absorb.

---

## Target architecture (modular monolith)
Keep Laravel monolith deployment for now, but split code into explicit internal modules with stable interfaces.

### 1) Integration Layer (Amazon-facing)
Create one adapter per external API family:

- `Amazon\SpApi\...`
- `Amazon\AdsApi\...`
- `Amazon\Notifications\...` (SQS/EventBridge/webhooks)

Rules:

- only this layer can use SDK-specific request/response classes,
- map SDK payloads to internal DTOs,
- centralize auth, retries, rate-limit backoff, request-id logging, and cost metadata.

### 2) Domain Layer (business logic)
Move app rules into domain services that do not depend on raw Amazon payloads:

- Orders domain,
- Listings domain,
- Ads/Spend domain,
- Metrics domain.

Rules:

- domain services consume DTOs from integration layer,
- domain outputs persistence commands/events,
- no direct HTTP/SDK calls from domain.

### 3) Application Layer (orchestration)
Use jobs/commands as orchestrators:

- schedule/trigger ingestion,
- invoke domain workflows,
- emit outcome events/metrics,
- enforce idempotency and run windows.

This layer should be thin and observable.

### 4) Data Layer
Standardize persistence patterns:

- explicit repositories for major aggregates (orders, listing reports, ad reports, daily metrics),
- idempotency keys for every external event/report/document,
- append-only ingestion log for replay/debug,
- materialized reporting tables for dashboard speed.

---

## API strategy aligned to Amazon direction

### A. Official SDK-first contract
Define a policy:

- all new Amazon endpoints must be integrated via official SDK/client,
- deprecated endpoints are wrapped in a single compatibility adapter,
- zero direct handcrafted endpoint calls outside adapters.

### B. Async-first where applicable
For operations Amazon positions as report/async:

- queue report request,
- persist request metadata + retry cadence,
- poll in bounded batches,
- ingest documents through typed parsers,
- checkpoint at each state transition.

### C. Deprecation readiness
Add a simple deprecation registry (config + docs):

- endpoint name,
- deprecation date,
- replacement endpoint/flow,
- migration owner,
- status.

Then expose an Artisan command to print imminent deadlines.

### D. Charging model awareness
Track and limit API spend by design:

- annotate each API call type with estimated cost weight,
- write daily call/cost counters by domain + endpoint,
- enforce per-domain budgets (soft alert first, hard throttle optionally),
- prioritize high-value sync paths when budget pressure occurs.

---

## Cross-cutting standards

### Observability
- structured logs with correlation IDs and Amazon request IDs,
- metrics: success/failure, latency, retries, throttles, stale queue depth,
- alerting on ingestion lag and repeated terminal failures.

### Reliability
- standardized retry strategy (jittered exponential + max age),
- dead-letter handling for poison events/messages,
- idempotent upserts across all ingest paths,
- replay tooling for failed report documents.

### Schema governance
- store raw payload snapshots for audit/debug,
- parse into normalized tables via versioned transformers,
- never break old parsers when Amazon evolves response fields.

### Security/compliance
- secrets only in environment/secret manager,
- avoid storing PII unless needed; set retention policy,
- log redaction for addresses/tokens.

---

## Recommended phased migration

### Phase 0 (1-2 weeks): establish guardrails
- add integration adapters around existing Amazon calls,
- centralize retry/rate-limit/request-id logging,
- add deprecation registry + `amazon:deprecations` command,
- add cost counter middleware for API calls.

### Phase 1 (2-4 weeks): stabilize critical flows
- migrate orders flow fully behind adapter + DTO boundary,
- standardize report state machine for listings + ads,
- add idempotency keys and replay support for report ingestion.

### Phase 2 (2-4 weeks): optimize for charging model
- introduce endpoint budgets and sync prioritization,
- shift low-value frequent polling to adaptive cadence,
- precompute high-cost metrics in daily jobs only.

### Phase 3 (ongoing): simplify and scale
- remove compatibility adapters for retired endpoints,
- split heavy domains into separate workers/queues if needed,
- optionally extract modules into services only when operational load justifies it.

---


## Fast-track mode for a time-limited owner-operator
If the priority is **accurate business data ASAP**, run this in fast-track mode rather than in broad architecture phases.

### Fast-track principles
- prefer **stabilize + observe** over perfect abstractions,
- change one critical flow at a time (Orders first),
- ship in 1-2 day slices with rollback safety,
- every slice must include verification commands and expected outputs.

### 10-business-day accelerated plan

#### Days 1-2: Orders reliability hardening (highest business value)
- keep current scheduler cadence,
- add explicit request-id and status logging to every Orders API call,
- add counters for `orders.getOrders`, `orders.getOrderItems`, `orders.getOrderAddress`,
- add one command to run a bounded sync + summary output for manual validation.

**Exit criteria:** You can run one command and see fetched counts, retries, and errors in one place.

#### Days 3-4: Adapter seam without full refactor
- create `app/Integrations/Amazon/SpApi/OrdersAdapter.php`,
- move only API invocation + retry logic into adapter,
- keep persistence logic in existing service for now.

**Exit criteria:** `OrderSyncService` no longer calls SDK methods directly.

#### Days 5-6: Data correctness guardrails
- create daily reconciliation command for order totals/status transitions,
- add anomaly report (missing items, missing addresses, zero totals where unexpected),
- save reconciliation summary rows for dashboard/alerts.

**Exit criteria:** Daily automated report tells you if business-critical order data drifted.

#### Days 7-8: Cost/deprecation controls (minimum viable)
- add endpoint usage table and record daily counts,
- add deprecation registry config,
- add `amazon:deprecations` command and schedule a daily warning.

**Exit criteria:** You can see what may break next and what costs are rising.

#### Days 9-10: Listings/Ads alignment pass
- apply same adapter + counters pattern to one listings report flow and one ads report flow,
- unify report state labels (`queued`, `processing`, `ready`, `ingested`, `failed`, `stale`),
- document operator playbook for stuck reports.

**Exit criteria:** Core domains share one predictable async/report operational model.

### Owner-friendly weekly operating cadence
- **Monday (30 min):** run reconciliation + deprecations check.
- **Daily (10 min):** review ingest lag/errors from logs/dashboard.
- **Friday (30 min):** compare API usage/cost trend and adjust polling limits.

### “Do this next” checklist (copy/paste for VS Code)
1. Create `OrdersAdapter` and route existing SDK calls through it.
2. Add `amazon_endpoint_usage_daily` migration + model.
3. Increment counters in Orders calls.
4. Add `orders:reconcile` command with summary output.
5. Add `config/amazon_deprecations.php` + `amazon:deprecations` command.
6. Update `DOCUMENTATION.md` with exact run commands and expected checks.



## Parallel delivery: ship features while modernizing
Yes — this is realistic, if you run **two lanes** in parallel.

### Lane A: Business features (speed lane)
Use for user-facing features or urgent reporting improvements.

Rules:
- keep PRs small (1 feature, 1 rollback path),
- no new direct Amazon SDK calls outside adapters,
- if feature touches Amazon data, include one correctness check command in PR.

### Lane B: Platform hardening (safety lane)
Use for adapters, retries, deprecation controls, and cost/usage telemetry.

Rules:
- every hardening PR should reduce risk in one flow only,
- do not mix broad refactors with feature UI changes,
- update runbook commands when operator behavior changes.

### Suggested weekly split for a time-limited owner
- 70% Lane A (business-visible features)
- 30% Lane B (modernization guardrails)

If reliability slips (failed syncs, stale reports), temporarily switch to 50/50 until green.

### Branch/PR workflow in VS Code
1. Create one branch per task (`feature/...` or `hardening/...`).
2. Keep each branch mergeable within 1–2 days.
3. Run pre-flight checklist before opening PR.
4. Merge frequently to avoid long-lived divergence.

### Definition of realistic
This approach is working when:
- new features continue shipping weekly,
- no increase in Amazon API error backlog,
- reconciliation checks stay green,
- deprecated endpoint count trends down.

## Amazon best-practice guardrails (non-negotiable)
Use these as release gates so speed never overrides Amazon alignment.

1. **Official docs + SDK first**
   - No new endpoint integration without linking the current Amazon doc page in the PR.
   - Prefer official SDK/client patterns; if not possible, add a temporary adapter with a removal date.

2. **Least privilege + secret hygiene**
   - Use IAM least-privilege policies for SQS/related AWS resources.
   - Never commit tokens/keys; rotate credentials on suspected exposure.

3. **Rate-limit and retry compliance**
   - Respect endpoint-specific throttles.
   - Use exponential backoff with jitter and bounded retries.
   - Log Amazon request IDs for every non-2xx response.

4. **Idempotency everywhere**
   - Every ingest path must be safe to replay.
   - Deduplicate by stable external identifiers (order/report/message IDs).

5. **Deprecation and version discipline**
   - Track deprecation dates with an owner.
   - Block net-new use of deprecated operations.

6. **Data correctness over freshness when in conflict**
   - If data appears inconsistent, run reconciliation before increasing sync frequency.

### Pull request quality gate (copy/paste)
- [ ] Linked Amazon documentation for affected endpoint/feature.
- [ ] Confirmed operation is not deprecated (or documented migration path).
- [ ] Retry + throttle handling verified.
- [ ] Idempotency path verified (safe re-run).
- [ ] Request IDs and error context logged.
- [ ] Runbook (`DOCUMENTATION.md`) updated if operator behavior changed.

## Definition of done for modernization
A modernization increment is complete when:

1. endpoint usage is through adapter + DTO only,
2. retries/idempotency/observability are standardized,
3. deprecation + cost impacts are visible in dashboards/alerts,
4. old path is removed (not just bypassed),
5. runbook/docs are updated in the same PR.

---

## Practical next actions for this repository
1. Introduce `app/Integrations/Amazon/...` namespace and move direct SDK setup there.
2. Add shared API client wrapper for request-id capture, throttle handling, and cost tagging.
3. Create a unified report request state model (queued, requested, processing, ready, ingested, failed, stale).
4. Add an `amazon_endpoint_usage_daily` table and record call counts per endpoint.
5. Add a deprecation config file + command + scheduler warning.
6. Update `DOCUMENTATION.md` to include cost/deprecation operational checks.

These six items give immediate structure without forcing a risky rewrite.
