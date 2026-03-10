# jlevers to Official SP-API SDK Migration Checklist

This file tracks the migration from `jlevers/selling-partner-api` to the official Amazon SP-API SDK.

It is a living checklist and must be updated whenever legacy usage is discovered, changed, migrated, blocked, or deferred.

---

## Rules

- Record any discovered legacy `jlevers/selling-partner-api` usage.
- Update entries when migration work starts or completes.
- Do not mark an item as `migrated` unless the legacy usage has actually been removed from that area of code.
- Prefer incremental migration in the course of related work.
- Do not remove the package dependency until all relevant items are migrated or explicitly deferred.

---

## Allowed Status Values

- `discovered`
- `planned`
- `in_progress`
- `migrated`
- `blocked`
- `deferred`

---

## Summary

| Status       | Count |
|--------------|-------|
| discovered   | 0     |
| planned      | 4     |
| in_progress  | 0     |
| migrated     | 6     |
| blocked      | 1     |
| deferred     | 0     |

---

### ITEM-001
- Status: blocked
- File path: `composer.json`, `composer.lock`
- Class/module: Composer dependency graph (`jlevers/selling-partner-api`, `highsidelabs/laravel-spapi`)
- Legacy usage type: package reference and transitive dependency lock-in
- Purpose: installs the legacy SP-API package directly and via the Laravel wrapper package
- Official SDK replacement target: `amzn-spapi/sdk` plus first-party app factories/adapters (remove legacy packages after callsites are migrated)
- Notes: `composer.json` still requires both `jlevers/selling-partner-api` and `highsidelabs/laravel-spapi`; `composer.lock` confirms wrapper depends on jlevers. Removal is blocked until remaining runtime usages below are migrated.

### ITEM-002
- Status: migrated
- File path: `app/Http/Controllers/MultiSellerController.php`
- Class/module: `App\Http\Controllers\MultiSellerController`
- Legacy usage type: direct vendor include, wrapper-specific credentials model, static `makeApi` legacy API construction
- Purpose: demo/manual multi-seller seller-participation calls through legacy wrapper patterns
- Official SDK replacement target: dedicated seller participation service using `amzn-spapi/sdk` client factory + explicit credential provider
- Notes: removed unused controller (no route/internal references), eliminating `vendor/jlevers` include and wrapper-specific legacy usage from this area.

### ITEM-003
- Status: planned
- File path: `app/Integrations/Amazon/SpApi/SpApiClientFactory.php`
- Class/module: `App\Integrations\Amazon\SpApi\SpApiClientFactory`
- Legacy usage type: centralized wrapper around legacy static connector builder
- Purpose: constructs seller connector used by integration adapters
- Official SDK replacement target: official SP-API client factory that returns operation clients (or typed API clients) from `amzn-spapi/sdk`
- Notes: currently returns `SellingPartnerApi::seller(...)`, so this is a key migration seam for reducing churn across consumers.

### ITEM-004
- Status: migrated
- File path: `app/Services/RegionConfigService.php`
- Class/module: `App\Services\RegionConfigService`
- Legacy usage type: legacy enum coupling (`SellingPartnerApi\Enums\Endpoint`, `Region`)
- Purpose: resolves region/endpoint and config for SP-API requests
- Official SDK replacement target: app-owned region/endpoint resolver decoupled from jlevers enums, mapped to official SDK endpoint config
- Notes: removed jlevers enum imports from this service and replaced enum return with app-owned string endpoint resolution (`spApiEndpoint()`); legacy enum conversion now occurs in `App\Support\Amazon\LegacySpApiEndpointResolver` at jlevers callsites.

### ITEM-005
- Status: planned
- File path: `app/Services/OrderSyncService.php`, `app/Services/MarketplaceService.php`, `app/Http/Controllers/OrderController.php`, `app/Console/Commands/SyncMarketplaces.php`
- Class/module: order and marketplace sync flow
- Legacy usage type: direct static connector construction and legacy type-hinted connectors in service APIs
- Purpose: order ingestion, marketplace sync, and related UI/controller orchestration
- Official SDK replacement target: injected official SDK order/seller clients via shared factory + service interfaces without jlevers types
- Notes: these files use `SellingPartnerApi::seller(...)` and/or `SellingPartnerApi` type hints, spreading legacy coupling into both services and HTTP/console entrypoints.

### ITEM-006
- Status: planned
- File path: `app/Services/ReportJobOrchestrator.php`, `app/Services/MarketplaceListingsSyncService.php`, `app/Services/UsFcInventorySyncService.php`, `app/Services/SpApiReportLifecycleService.php`, `app/Http/Controllers/ReportJobsController.php`, `app/Http/Controllers/SqsMessagesController.php`
- Class/module: SP-API report lifecycle and ingestion pipeline
- Legacy usage type: legacy DTO imports and connector creation for reports APIs
- Purpose: create/poll/download report documents and ingest listing/inventory report rows
- Official SDK replacement target: official reports client/request models from `amzn-spapi/sdk` behind report-specific services/jobs
- Notes: pipeline imports `SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification` and uses jlevers connector instances across orchestration and controller download endpoints.

### ITEM-007
- Status: planned
- File path: `app/Services/OrderFeeEstimateService.php`, `app/Services/PendingOrderEstimateService.php`
- Class/module: fee estimation and pending-order pricing estimators
- Legacy usage type: product fees/pricing DTO and connector dependencies from jlevers
- Purpose: estimate fees/prices and persist derived financial values
- Official SDK replacement target: official product-fees/pricing clients and request models via injected SDK adapters
- Notes: `OrderFeeEstimateService` imports multiple `SellingPartnerApi\Seller\ProductFeesV0\Dto\*` classes and both services instantiate jlevers connectors per region.

### ITEM-008
- Status: migrated
- File path: `app/Http/Controllers/NotificationSubscriptions.php`
- Class/module: `App\Http\Controllers\NotificationSubscriptions`
- Legacy usage type: controller-level connector creation and jlevers notifications DTO usage
- Purpose: manage SP-API destinations/subscriptions from web UI
- Official SDK replacement target: notification subscription service using official SDK notifications models/clients + thin controller
- Notes: migrated to official Notifications v1 client/models via `OfficialSpApiService::makeNotificationsV1Api()` and `SpApi\\Model\\notifications\\v1\\*` request objects; removed jlevers connector and DTO usage from this controller.

### ITEM-009
- Status: migrated
- File path: `app/Console/Commands/CheckSellerWalletAccess.php`
- Class/module: `App\Console\Commands\CheckSellerWalletAccess`
- Legacy usage type: command-level connector construction with legacy client
- Purpose: probe Seller Wallet API access per configured region
- Official SDK replacement target: wallet probe service using official SDK wallet client injected into command
- Notes: command now uses `OfficialSpApiService::makeSellerWalletAccountsApi()` and official `SpApi\\Api\\sellerWallet\\v2024_03_01\\AccountsApi` operations (`listAccounts`, `listAccountBalances`).

### ITEM-010
- Status: migrated
- File path: `config/spapi.php`
- Class/module: SP-API legacy package config surface
- Legacy usage type: legacy config file/keys built around old wrapper conventions
- Purpose: stores package-specific SP-API connection settings (`SPAPI_*` keys)
- Official SDK replacement target: consolidated `config/amazon.php` SP-API section consumed by official SDK factory/resolvers
- Notes: removed unused legacy config file; no application references to `config('spapi...')` were found before deletion.

### ITEM-011
- Status: migrated
- File path: `docs/LANDED_COST_SDK_REFACTOR_PLAN.md`
- Class/module: landed cost/reporting refactor plan
- Legacy usage type: documentation references legacy static connector pattern
- Purpose: migration guidance for landed-cost/reporting refactor scope
- Official SDK replacement target: docs updated to reference official SDK client factory and typed service boundaries only
- Notes: removed explicit `SellingPartnerApi::seller(...)` reference and now documents legacy connector usage generically with official SDK client-factory direction.
