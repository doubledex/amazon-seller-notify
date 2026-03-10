# Amazon API Legacy Version Migration Checklist

Date: 2026-03-10
Scope: migration tracking for legacy Amazon API version usage to current Amazon API versions.

Allowed statuses:
- `discovered`
- `planned`
- `blocked`
- `deferred`

## Summary

| Status | Count |
|---|---:|
| discovered | 0 |
| planned | 0 |
| blocked | 0 |
| deferred | 1 |

## Completed Units (No Legacy Usage Remaining)

- `API-001` Orders API migration (`v0` -> `v2026-01-01`) completed.
- `API-002` Product Pricing migration (`v0` -> `v2022-05-01`) completed.
- `API-003` Fulfillment Inbound migration (`v0` runtime flow -> `v2024-03-20` flow) completed.
- `API-004` Ads legacy fallback retirement (`/v2/sp/campaigns/report`, `/v2/reports/{id}`) completed.

## Remaining Migration Units

### API-005
- File path: `app/Services/Amazon/OfficialSpApiService.php`, `app/Services/Amazon/Inbound/InboundShipmentSyncService.php`
- Class/module name: Inbound shipment sync fallback behavior
- Legacy usage type: official SDK inbound `v0` fallback (`makeInboundV0Api`, `getShipments`, `getShipmentItemsByShipmentId`)
- Purpose: maintain shipment ingestion when `v2024-03-20` inbound plans do not expose shipment IDs for current seller workflow
- Official SDK replacement target: `v2024-03-20` only flow with shipment/item parity (`listInboundPlans` + `getInboundPlan` + `getShipment` + `listShipmentItems`)
- Status: deferred
- Notes: retained intentionally after production diagnostics on 2026-03-10 showed active plans with zero shipments from `getInboundPlan(...)->getShipments()`. Fallback remains strictly in official Amazon SDK (`amzn-spapi/sdk`), not `jlevers`.

## Out of Scope (Current APIs Already on Current Versions)

- `Finances v2024-06-19`
- `Reports v2021-06-30`
- `Catalog Items v2022-04-01`
- `Notifications v1`
- `Sellers v1`
- `Seller Wallet v2024-03-01`
- `Product Fees v0` (no newer version currently used/required in this codebase)
