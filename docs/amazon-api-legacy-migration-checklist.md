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
| deferred | 0 |

## Completed Units (No Legacy Usage Remaining)

- `API-001` Orders API migration (`v0` -> `v2026-01-01`) completed.
- `API-002` Product Pricing migration (`v0` -> `v2022-05-01`) completed.
- `API-003` Fulfillment Inbound migration (`v0` runtime flow -> `v2024-03-20` flow) completed.
- `API-004` Ads legacy fallback retirement (`/v2/sp/campaigns/report`, `/v2/reports/{id}`) completed.
- `API-005` Shared factory cleanup completed (`makeInboundV0Api` removed from service/factory surface).

## Remaining Migration Units
- None.

## Out of Scope (Current APIs Already on Current Versions)

- `Finances v2024-06-19`
- `Reports v2021-06-30`
- `Catalog Items v2022-04-01`
- `Notifications v1`
- `Sellers v1`
- `Seller Wallet v2024-03-01`
- `Product Fees v0` (no newer version currently used/required in this codebase)
