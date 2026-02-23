<?php

namespace App\Console\Commands;

use App\Services\UsFcInventorySyncService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;

class SyncUsFcInventory extends Command
{
    protected $signature = 'inventory:sync-us-fc
        {--region=NA}
        {--marketplace=ATVPDKIKX0DER}
        {--report-type=GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA}
        {--max-attempts=30}
        {--sleep-seconds=5}
        {--debug-json : Print raw SP-API reply payloads}
        {--yesterday : Use yesterday in app timezone}
        {--start-date= : Start date (YYYY-MM-DD)}
        {--end-date= : End date (YYYY-MM-DD)}';

    protected $description = 'Sync US FBA inventory by fulfillment center from SP-API report data.';

    public function handle(UsFcInventorySyncService $service): int
    {
        $startDate = (string) ($this->option('start-date') ?? '');
        $endDate = (string) ($this->option('end-date') ?? '');
        if ((bool) $this->option('yesterday')) {
            $tz = config('app.timezone', 'UTC');
            $yesterday = Carbon::yesterday($tz)->toDateString();
            $startDate = $yesterday;
            $endDate = $yesterday;
        }

        $result = $service->sync(
            (string) $this->option('region'),
            (string) $this->option('marketplace'),
            (string) $this->option('report-type'),
            (int) $this->option('max-attempts'),
            (int) $this->option('sleep-seconds'),
            $startDate !== '' ? $startDate : null,
            $endDate !== '' ? $endDate : null,
            (bool) $this->option('debug-json'),
        );

        if (!($result['ok'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'US FC inventory sync failed.'));
            if ((bool) $this->option('debug-json')) {
                $this->line('Debug payload:');
                $json = json_encode($result['debug_payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->line($json !== false ? $json : 'null');
            }
            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'US FC inventory sync complete.'));
        $this->line('Report ID: ' . (string) ($result['report_id'] ?? 'n/a'));
        if (!empty($result['report_type_used'])) {
            $this->line('Report type used: ' . (string) $result['report_type_used']);
        } elseif (!empty($result['report_type'])) {
            $this->line('Report type: ' . (string) $result['report_type']);
        }
        if (!empty($result['attempted_report_types']) && is_array($result['attempted_report_types'])) {
            $this->line('Report types attempted: ' . implode(', ', array_map('strval', $result['attempted_report_types'])));
        }
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

        if ((bool) $this->option('debug-json')) {
            $this->line('Debug payload:');
            $json = json_encode($result['debug_payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $this->line($json !== false ? $json : 'null');
        }

        return self::SUCCESS;
    }
}
