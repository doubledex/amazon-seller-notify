# DOCUMENTATION

This file is the operational reference for keeping this project running in dev and production.

## Keep Updated (Required)
Update this file whenever any of these change:
- `routes/console.php` scheduled jobs
- New/changed Artisan commands used by the team
- Required `.env` keys for Amazon SP-API, Amazon Ads API, or SQS
- Report ingestion architecture (queueing, polling, metrics refresh)

## Production / Server Setup

### 1. Required Environment Variables
Minimum required for current scheduled flows:

- Amazon SP-API
  - `AMAZON_SP_API_CLIENT_ID`
  - `AMAZON_SP_API_CLIENT_SECRET`
  - `AMAZON_SP_API_REFRESH_TOKEN`
  - `AMAZON_SP_API_APPLICATION_ID`
  - `AMAZON_SP_API_MARKETPLACE_IDS` (comma-separated)
  - `AMAZON_SP_API_ENDPOINT` (default `EU`)
  - Legacy fallback supported: `AMAZON_SP_API_ENDPOINTS`
  - Optional multi-region keys (for EU/NA/FE foundations):
    - `AMAZON_SP_API_REGIONS`
    - `AMAZON_SP_API_{EU|NA|FE}_ENDPOINT`
    - `AMAZON_SP_API_{EU|NA|FE}_CLIENT_ID`
    - `AMAZON_SP_API_{EU|NA|FE}_CLIENT_SECRET`
    - `AMAZON_SP_API_{EU|NA|FE}_REFRESH_TOKEN`
    - `AMAZON_SP_API_{EU|NA|FE}_APPLICATION_ID`
    - `AMAZON_SP_API_{EU|NA|FE}_MARKETPLACE_IDS`

- Amazon Ads API
  - `AMAZON_ADS_CLIENT_ID`
  - `AMAZON_ADS_CLIENT_SECRET`
  - `AMAZON_ADS_REFRESH_TOKEN`
  - `AMAZON_ADS_BASE_URL` (default `https://advertising-api-eu.amazon.com`)
  - Optional multi-region keys (for EU/NA/FE foundations):
    - `AMAZON_ADS_DEFAULT_REGION` (default `EU`)
    - `AMAZON_ADS_REGIONS`
    - `AMAZON_ADS_{EU|NA|FE}_CLIENT_ID`
    - `AMAZON_ADS_{EU|NA|FE}_CLIENT_SECRET`
    - `AMAZON_ADS_{EU|NA|FE}_REFRESH_TOKEN`
    - `AMAZON_ADS_{EU|NA|FE}_BASE_URL`

- AWS / SQS
  - `AWS_ACCESS_KEY_ID`
  - `AWS_SECRET_ACCESS_KEY`
  - `AWS_DEFAULT_REGION`
  - `SQS_QUEUE_URL`

### 2. Pull / Build / Deploy Commands
Run on deploy (or after pulling changes):

```bash
git pull
composer install --no-interaction --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:clear
php artisan cache:clear
```

Why `npm run build` is required:
- Tailwind classes are compiled into `public/build` by Vite during build.
- If build is skipped, CSS/JS changes in `resources/` are not reflected in production.

### 3. Cron for Laravel Scheduler (Required)
Add this to crontab for the app user:

```cron
* * * * * cd /home/david/dev/amazon-seller-notify && php artisan schedule:run >> /home/david/dev/amazon-seller-notify/storage/logs/scheduler.log 2>&1
```

If your deploy path differs, update the path accordingly.

### 4. Current Scheduled Jobs
Defined in `routes/console.php`:

- `marketplaces:sync` twice daily at 01:00 and 13:00
- `listings:queue-reports` daily at 03:30
- `listings:poll-reports --limit=200` every ten minutes
- `map:geocode-missing --limit=250` daily at 02:30
- `map:geocode-missing-cities --limit=250 --older-than-days=14` daily at 02:35
- `orders:sync --days=7 --max-pages=5 --items-limit=50 --address-limit=50` hourly
- `orders:refresh-estimates --days=14 --limit=300 --max-lookups=80 --stale-minutes=180` every 30 minutes
- `orders:sync --days=30 --max-pages=20 --items-limit=300 --address-limit=300` daily at 03:50 (reconciliation for late status changes)
- `sqs:process` every minute
- `ads:queue-reports` daily at 04:40
- `ads:poll-reports --limit=200` every five minutes
- `metrics:refresh` daily at 05:00

### 5. Verify Scheduler Health

```bash
php artisan schedule:list
php artisan sqs:process
php artisan ads:poll-reports --limit=50
```

Check logs:
- `storage/logs/laravel.log`
- `storage/logs/scheduler.log`

## Dev Environment Artisan Commands (With Why)

### Ads Reporting / Spend
- `php artisan ads:test-connection`
  - Why: verify Amazon Ads credentials and profile visibility.

- `php artisan ads:queue-reports --from=YYYY-MM-DD --to=YYYY-MM-DD [--profile-id=...] [--ad-product=...]`
  - Why: create async Ads report requests and persist report IDs for background processing.

- `php artisan ads:poll-reports --limit=200`
  - Why: poll outstanding report IDs, ingest completed files, update wait/retry state.

- `php artisan ads:sync-spend --from=YYYY-MM-DD --to=YYYY-MM-DD [options]`
  - Why: direct sync path (create + poll + ingest in one run). Useful for one-off validation.

### Metrics
- `php artisan metrics:refresh --from=YYYY-MM-DD --to=YYYY-MM-DD`
  - Why: recalculate daily UK/EU/NA sales + ad spend + ACOS metrics.

### Listings / Catalog
- `php artisan listings:sync-europe`
  - Why: refresh European listing/SKU/ASIN status data.

### US FC Inventory
- `php artisan inventory:sync-us-fc --region=NA --marketplace=ATVPDKIKX0DER`
  - Why: pull latest US FBA inventory by fulfillment center into local tables for reporting.

- `php artisan listings:queue-reports [--marketplace=...] [--report-type=...]`
  - Why: queue SP-API listings reports for persistent background processing.

- `php artisan listings:poll-reports --limit=200 [--marketplace=...]`
  - Why: poll queued SP-API listing reports and ingest completed report documents.

- `php artisan marketplaces:sync`
  - Why: sync marketplace participation metadata from SP-API.

### Orders
- `php artisan orders:sync --days=7 --max-pages=5 --items-limit=50 --address-limit=50`
  - Why: fetch and persist recent orders/items/addresses.

- `php artisan orders:refresh-estimates --days=14 --limit=300 --max-lookups=80 --stale-minutes=180`
  - Why: refresh temporary estimated line sales values for pending/unshipped items via SP-API pricing.

- `php artisan orders:sync --days=30 --max-pages=20 --items-limit=300 --address-limit=300`
  - Why: daily reconciliation window to catch delayed status/total updates (for example pending to canceled).

- `php artisan orders:backfill-mf`
  - Why: backfill marketplace facilitator flag from stored order item tax metadata.

- `php artisan orders:backfill-dates`
  - Why: repair/backfill `purchase_date` from raw order payloads.

### SQS / Notifications
- `php artisan sqs:process [--detail]`
  - Why: receive/process SQS notifications, dedupe/store, and delete handled queue messages.

- `php artisan app:update-sqs-messages`
  - Why: post-processing/update command for historical SQS rows.

### Geocoding
- `php artisan map:geocode-missing --limit=250`
  - Why: backfill missing postal-code geocoding data.

- `php artisan map:geocode-missing-cities --limit=250 --older-than-days=14`
  - Why: persist city-level fallback geocodes for older stable orders when postal code is unavailable.

- Map rendering (`/orders?view=map`) is DB-only for geocoding.
  - Why: avoid slow/variable page loads and third-party geocoder calls during page requests.

## Notes on Ads Async Reliability
- Queue + poll flow is the primary path for long-running reports.
- Report state is persisted with retries and next-check scheduling.
- For troubleshooting, use `/ads/reports` in the app to inspect status, retries, and request IDs.

## Notes on SP-API Listings Reliability
- Listings reports are now queued and polled asynchronously via `sp_api_report_requests`.
- Retry state includes `retry_count`, `next_check_at`, `last_http_status`, and `last_request_id`.
- Long-running/stuck report requests are flagged after 1 hour.
