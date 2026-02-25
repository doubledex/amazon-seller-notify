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

- `php artisan orders:link-products --limit=1000`
  - Why: link `order_items.product_id` using configured `product_identifiers` (SKU first, then ASIN).

- `php artisan orders:sync --days=30 --max-pages=20 --items-limit=300 --address-limit=300`
  - Why: daily reconciliation window to catch delayed status/total updates (for example pending to canceled).

- `php artisan orders:backfill-mf`
  - Why: backfill marketplace facilitator flag from stored order item tax metadata.

- `php artisan orders:backfill-dates`
  - Why: repair/backfill `purchase_date` from raw order payloads.

### Products (Source Of Truth)
- `php artisan products:bootstrap-from-orders --limit=1000 [--reset=1]`
  - Why: seed `products` from unique ASINs, attach identifiers, and link `order_items.product_id`. Use `--reset=1` to rebuild from scratch.

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

## Fast-Track Owner Checks (10-15 min/day)
If you are prioritizing business continuity over deeper refactors, do these checks daily:

1. `php artisan orders:sync --days=2 --max-pages=3 --items-limit=100 --address-limit=100`
   - Validate fresh order ingestion and check for API errors/retries.
2. `php artisan ads:poll-reports --limit=50`
   - Clear completed ad reports and reduce stale backlog.
3. `php artisan listings:poll-reports --limit=50`
   - Clear completed listings report backlog.
4. Review `storage/logs/laravel.log` for repeated throttling or terminal failures.

Weekly:
- `php artisan schedule:list`
- `php artisan sqs:process --detail`

As modernization work lands, add:
- `php artisan orders:reconcile`
- `php artisan amazon:deprecations`


## Amazon Best-Practice Pre-Flight (before merging API changes)
Run this checklist for every Amazon API touching PR:

1. Confirm endpoint status in Amazon docs (active vs deprecated).
2. Confirm you used official SDK/client pattern or documented exception.
3. Verify throttling strategy and retry behavior (jittered exponential backoff).
4. Verify idempotent writes/replays (safe to rerun command/job).
5. Verify logs capture request IDs for failures.
6. Add/update any required `.env` keys and operator commands in this file.

Recommended quick validation commands:
- `php artisan schedule:list`
- `php artisan orders:sync --days=2 --max-pages=2 --items-limit=50 --address-limit=50`
- `php artisan listings:poll-reports --limit=20`
- `php artisan ads:poll-reports --limit=20`


## Dual-Track VS Code Workflow (Features + Modernization)
Yes, it is realistic to keep shipping features while modernizing.

Use two work types:
- `feature/*` branches: user-visible functionality and business reporting improvements.
- `hardening/*` branches: adapter moves, retries, idempotency, deprecations, telemetry.

Minimum PR rules:
1. One branch = one purpose (feature or hardening).
2. Include at least one verification command in PR description.
3. If Amazon API behavior changed, run the Pre-Flight checklist above.
4. Merge within 1–2 days; avoid long-lived branches.

Weekly target:
- 3 feature PRs + 1 hardening PR (adjust based on incidents).

## Git Branch Safety (Dev -> GitHub -> Production)
Use this when your production branch is `release/pre-us-baseline`.

### Why the `work` errors happened
If you see:
- `error: pathspec 'work' did not match any file(s) known to git`
- `error: src refspec work does not match any`

it means your local repository does not have a branch named `work`.

### Correct workflow for your setup
1. Commit or stash local edits first (Git will block pull/merge with unstaged changes).
2. Work from `release/pre-us-baseline` unless you intentionally create another branch.

```bash
# Check where you are and what is modified
git branch --show-current
git status --short

# If files are modified, either commit or stash
git add -A && git commit -m "WIP: save local changes"
# OR
git stash push -u -m "temp before pull"

# Update release branch from GitHub
git fetch origin
git checkout release/pre-us-baseline
git pull --ff-only origin release/pre-us-baseline
```



### Exact fix for this error output
If you ran `git checkout work` and got those errors, do this instead:

```bash
# confirm branch + local edits
git branch --show-current
git status --short

# if you have local edits, save them first
git add -A && git commit -m "WIP: save before sync"
# OR: git stash push -u -m "temp before sync"

# stay on release branch and sync from GitHub
git checkout release/pre-us-baseline
git pull --ff-only origin release/pre-us-baseline
```

If `work` exists on GitHub but not locally, create a local tracking branch:

```bash
git fetch origin
git checkout -b work origin/work
```

If you get `fatal: couldn't find remote ref work` or `origin/work is not a commit`, that means `work` does not exist on GitHub.
In that case, either:

1. Continue with `release/pre-us-baseline` only (simplest), or
2. Create and publish `work` from your current local branch:

```bash
git checkout -b work
# or: git checkout work   (if it already exists locally)
git push -u origin work
```

Only create/push a brand new `work` branch if you intentionally want that name and it does not already exist on remote:

```bash
git checkout -b work
git push -u origin work
```

Then merge it into release on dev, and only pull release on production:

```bash
git checkout release/pre-us-baseline
git pull --ff-only origin release/pre-us-baseline
git merge --no-ff work
git push origin release/pre-us-baseline
```


### After you merge `work` into `release/pre-us-baseline`, do you need to checkout `work` again?
Short answer: **only when you want to continue coding on `work`**.

- After `git merge --no-ff work`, you are still on `release/pre-us-baseline`.
- If you are done promoting changes and want to deploy, stay on `release/pre-us-baseline`.
- If you want to keep developing the next set of changes, switch back to `work`:

```bash
git checkout work
git pull --ff-only origin work
```

A simple rhythm is:
1. Work on `work`.
2. Merge `work` -> `release/pre-us-baseline`.
3. Push `release/pre-us-baseline` and deploy from it.
4. Checkout `work` again for the next round of edits.

