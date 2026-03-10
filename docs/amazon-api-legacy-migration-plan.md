# Amazon API Legacy Version Migration Plan

Date: 2026-03-10
Scope: audit-only plan for migrating legacy Amazon API versions to current versions.

## Audit Scope

Reviewed:
- `composer.json`, `composer.lock`
- `app/`, `config/`, `routes/`, `tests/`, `docs/`
- SP-API factories/adapters/services/controllers/jobs/commands
- Amazon Ads reporting services and fallbacks

## Current API Usage Snapshot (Updated After Migration Work)

SP-API usage in code:
- Orders API `v2026-01-01` (current migration target adopted)
- Fulfillment Inbound API `v2024-03-20` (current migration target adopted for runtime sync)
- Product Pricing API `v2022-05-01` (current migration target adopted)
- Product Fees API `v0` (still current)
- Reports API `v2021-06-30` (current)
- Catalog Items API `v2022-04-01` (current)
- Finances API `v2024-06-19` (current)
- Sellers API `v1` (current)
- Notifications API `v1` (current)
- Seller Wallet API `v2024-03-01` (current)

Amazon Ads usage in code:
- Reporting API `/reporting/reports` (current path in this codebase)
- Legacy fallback paths `/v2/sp/campaigns/report` and `/v2/reports/{reportId}` removed

## Legacy Migration Units

1. Fulfillment Inbound migration (`v0` -> `v2024-03-20`)
- Why: `v0` is legacy; newer inbound model is current.
- Impacted components: inbound shipment sync service and factory methods.

## Execution Plan

Completed:
- Orders API migration completed (`v0` removed; `v2026-01-01` path live).
- Product Pricing migration completed (`v0` removed; `v2022-05-01` path live).
- Ads fallback retirement completed (legacy `/v2/...` fallback removed).
- Inbound runtime migration completed (`getShipments/getShipmentItemsByShipmentId` replaced with `listInboundPlans/getInboundPlan/getShipment/listShipmentItems` on `v2024-03-20`).

Remaining Phase: Fulfillment Inbound API
- Remaining cleanup completed: `makeInboundV0Api` methods removed from shared factory surfaces.

## Risk Controls

- Maintain region/marketplace/profile context explicitly during each migration.
- Keep behavior-compatible output contracts at service boundaries.
- Migrate one unit at a time with targeted tests.
- Gate removal of old paths behind passing sync/reporting smoke tests.

## Priority Order

1. No remaining legacy API-version migration units in current audit scope.

## Reference Sources

- SP-API models index: https://developer-docs.amazon.com/sp-api/lang-en_EN/docs/sp-api-models
- Orders API deprecation/migration: https://developer-docs.amazon.com/sp-api/docs/orders-api
- SP-API deprecations: https://developer-docs.amazon.com/sp-api/lang-US/docs/sp-api-deprecations
