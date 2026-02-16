<?php

namespace App\Console\Commands;

use App\Services\UsFcInventorySyncService;
use Illuminate\Console\Command;

class SyncUsFcInventory extends Command
{
    protected $signature = 'inventory:sync-us-fc
        {--region=NA}
        {--marketplace=ATVPDKIKX0DER}
        {--report-type=GET_LEDGER_SUMMARY_VIEW_DATA}
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
        $this->line('Rows parsed: ' . (int) ($result['rows_parsed'] ?? 0));
        $this->line('Rows missing FC ID: ' . (int) ($result['rows_missing_fc'] ?? 0));
        $this->line('Rows missing SKU/FNSKU: ' . (int) ($result['rows_missing_sku'] ?? 0));

        $sampleKeys = $result['sample_row_keys'] ?? [];
        if (is_array($sampleKeys) && !empty($sampleKeys)) {
            $this->line('Sample row keys where FC ID was missing:');
            foreach ($sampleKeys as $keys) {
                if (!is_array($keys)) {
                    continue;
                }
                $this->line(' - ' . implode(', ', array_map('strval', $keys)));
            }
        }

        return self::SUCCESS;
    }
}
