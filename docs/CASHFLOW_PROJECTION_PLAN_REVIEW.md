# Cashflow projection plan (single consolidated review)

## 1) Objective
Build a cashflow projection/timing capability from Amazon Finances v2024-06-19 that can:
- Show expected payments for a given **day**.
- Show expected payments for a given **week**.
- Show **today’s payment timing split by marketplace**.
- Keep core storage/aggregation in **UTC**, with optional marketplace-local rendering.

This document is the single source of truth for the plan and includes both the original projection design and the regional-routing reassessment.

## 2) Current-state notes
- Deferred/released concepts and maturity date are visible in financial payload contexts.
- Existing financial summary functionality is present, but projection-grade persistence is not yet complete.
- A prior regional-routing implementation attempt existed and was rolled back; the design insight remains valid for future implementation.

## 3) Core design principles
1. **Amazon payload fidelity first**
   - Preserve traceability back to SP-API payload fields.
2. **Event-normalization before UI**
   - Build a payment-event data model before dashboard/UX work.
3. **UTC-first correctness**
   - Store and aggregate in UTC; convert only for presentation.
4. **Deterministic aggregation**
   - Prefer DB-backed projections over heavy cache in early rollout.

## 4) Data model plan (projection-ready persistence)
Create a payment-event persistence layer (new table or equivalent extension) with at least:
- `canonical_transaction_id` (link deferred/released lifecycle)
- `amazon_transaction_id`
- `transaction_status` (e.g., `DEFERRED`, `RELEASED`)
- `transaction_type`
- `posted_date_utc`
- `maturity_date_utc`
- `effective_payment_date_utc` (derived)
- `amount`
- `currency`
- `marketplace_id`
- `region` (resolved routing region)
- `endpoint` (resolved API endpoint)
- raw/context snapshot fields for audit/debug

Recommended indexes:
- `(effective_payment_date_utc, marketplace_id)`
- `(maturity_date_utc, transaction_status)`
- `(canonical_transaction_id)`
- `(currency, effective_payment_date_utc)`

## 5) Ingestion and normalization plan
During Finances sync:
1. Pull transactions by order/period via official SDK adapter.
2. Extract maturity date from known context paths + recursive fallback.
3. Capture status and amount fields needed for timing math.
4. Compute `effective_payment_date_utc` rules:
   - released: posted/release timing
   - deferred: maturity-based expected timing
5. Persist with canonical linkage to pair deferred and later released events.
6. Upsert/idempotency rules must prevent duplicate projection events.

## 6) Projection service plan
Implement `CashflowProjectionService` with query methods:
- `forDay(date_utc, filters...)`
- `forWeek(week_start_utc, filters...)`
- `todayTimingByMarketplace(now_utc, filters...)`

Per bucket, return:
- `expected_total`
- `released_total`
- `deferred_total`
- `net_projection`
- grouped by `marketplace_id` and `currency`

## 7) API + response contract
Expose projection endpoints with filters:
- date/day/week range
- marketplace(s)
- region
- currency

Response should include:
- UTC bucket boundaries (authoritative)
- optional localized fields (`local_time`, `local_date`, timezone)
- metadata linking computed values back to source statuses/fields

## 8) Regional-routing considerations (from reassessment)
When regional SP-API routing is reintroduced:
1. Ensure complete `marketplaceId -> region` coverage in config.
2. Add startup/config validation + alerting for unmapped marketplaces.
3. Persist resolved `region` and `endpoint` for reconciliation.
4. If routing behavior changes, bump relevant summary/projection cache keys.

## 9) Cache strategy
- Early phase: minimal cache for projections; rely on deterministic DB aggregates.
- After stability: add short TTL caches for common day/week views.
- Any material routing or normalization logic change requires cache version bump.

## 10) Implementation phases
1. **Hardening**: mapping validation + observability for unmapped marketplace IDs.
2. **Persistence**: projection-ready financial event table + indexes.
3. **Normalization**: deferred/released linkage + effective payment date derivation.
4. **Service/API**: day/week/today projections split by marketplace/currency.
5. **Presentation**: optional marketplace-local time rendering.
6. **Optimization**: tuned indexes/caching after correctness validation.

## 11) Acceptance checks
- Sample orders from active regions return transactions consistently.
- Deferred and released flows show expected maturity/release timing fields.
- UTC day totals match recomputed SQL aggregates from raw persisted events.
- Marketplace-split outputs reconcile to the same UTC total.
- Repeated sync runs are idempotent (no duplicate projection events).

## 12) Out of scope (for this phase)
- ML-style payment prediction beyond explicit SP-API timing fields.
- Non-Amazon cash account forecasting.
- FX conversion policy design beyond source-currency reporting.
