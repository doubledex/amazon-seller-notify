<?php

namespace App\Console\Commands;

use App\Services\UsFcInventorySyncService;
use Illuminate\Console\Command;

class SyncUsFcInventory extends Command
{
    protected $signature = 'inventory:sync-us-fc
        {--region=NA}
        {--marketplace=ATVPDKIKX0DER}
        {--report-type=GET_AFN_INVENTORY_DATA}
        {--max-attempts=30}
        {--sleep-seconds=5}';

    protected $description = 'Sync US FBA inventory by fulfillment center from SP-API report data.';

    public function handle(UsFcInventorySyncService $service): int
    {
        $result = $service->sync(
            (string) $this->option('region'),
            (string) $this->option('marketplace'),
            (string) $this->option('report-type'),
            (int) $this->option('max-attempts'),
            (int) $this->option('sleep-seconds'),
        );

        if (!($result['ok'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'US FC inventory sync failed.'));
            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'US FC inventory sync complete.'));
        $this->line('Report ID: ' . (string) ($result['report_id'] ?? 'n/a'));
        $this->line('Rows upserted: ' . (int) ($result['rows'] ?? 0));

        return self::SUCCESS;
    }
}
