# Landed Cost + Reporting Refactor Guardrails (Official SDK / Latest APIs)

This plan expands the landed-cost and reporting proposals with a hard constraint:

- **Use official Amazon SDK/client patterns only** for SP-API integration.
- **Use currently active API models/operations** (avoid deprecated paths during refactor).
- **Upgrade legacy callsites while touching the domain** so we do not ship mixed-generation integrations.
- **Follow Amazon best practices** (throttling, request-id logging, idempotency, replay safety).

---

## 1) Non-Negotiable Engineering Rules

1. All new SP-API calls must be made through a shared connector factory/service abstraction.
2. Any touched legacy direct connector usage must be migrated in the same PR scope when practical.
3. Deprecated or fallback-only API paths must not be expanded; they should be explicitly reduced and removed.
4. Any API-facing PR must include a pre-flight verification based on `DOCUMENTATION.md` Amazon checklist.

---

## 2) Priority Upgrade Targets During Landed-Cost Refactor

### A. Centralize SP-API client creation

Current code mixes direct `SellingPartnerApi::seller(...)` construction in multiple services/controllers.
As we refactor landed cost + reporting, move touched flows to a centralized factory pattern.

Targets to prioritize when touched:

- `app/Services/OrderSyncService.php`
- `app/Services/MarketplaceListingsSyncService.php`
- `app/Services/ReportJobOrchestrator.php`
- `app/Services/UsFcInventorySyncService.php`
- `app/Http/Controllers/OrderController.php`
- `app/Http/Controllers/NotificationSubscriptions.php`

### B. Normalize config usage by region

Use `RegionConfigService` consistently for endpoint and credentials resolution; avoid ad-hoc region parsing.

### C. Ads API legacy fallback retirement plan

`AmazonAdsSpendSyncService` currently includes a legacy sponsored-products fallback path.
During reporting refactor, maintain compatibility short-term but create explicit telemetry and sunset criteria,
then remove fallback once modern path coverage is proven stable.

---

## 3) Landed Cost Domain Design (Identifier + Time Aware)

Implement landed cost at `asin + sku + marketplace (+ region)` granularity with effective-date handling.

### Required outcomes

1. Cost records can be attached to product identifiers (not only master product).
2. Costs can be composed from multiple lines (COG, packaging, shipping, duties, etc.).
3. Costs support per-unit and per-shipment allocation models.
4. Costs are resolvable by order-item date for historical accuracy.

---

## 4) Reporting Outcomes Required in This Program

1. **Orders report:** landed cost amount per order and/or order item.
2. **Daily sales + ads report:** landed cost totals added beside sales, fees, ad spend.
3. Margin/contribution fields derived in a deterministic way from sales, fees, ad spend, landed cost.

All derived fields must be replay-safe so historical re-runs produce consistent outputs.

---

## 5) Definition of Done for API Modernization in This Workstream

A story touching Amazon integration is done only if:

1. API usage follows official SDK/client pattern and central connector strategy.
2. Endpoint/model used is validated as active/current in Amazon docs.
3. Retries/backoff/request-id logging are preserved (or improved).
4. Idempotency/replay behavior is documented and tested for commands/jobs.
5. Operational docs are updated with any new commands/env keys.

