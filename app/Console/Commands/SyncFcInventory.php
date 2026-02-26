<?php

namespace App\Console\Commands;

use App\Services\UsFcInventorySyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncFcInventory extends Command
{
    protected $signature = 'inventory:sync-fc
        {--region=NA}
        {--marketplace=ATVPDKIKX0DER}
        {--report-type=GET_FBA_MYI_UNSUPPRESSED_INVENTORY_DATA}
        {--max-attempts=30}
        {--sleep-seconds=5}
        {--debug-json : Print raw SP-API reply payloads}
        {--yesterday : Use yesterday in app timezone}
        {--start-date= : Start date (YYYY-MM-DD)}
        {--end-date= : End date (YYYY-MM-DD)}';

    protected $description = 'Sync global FBA inventory by fulfillment center from SP-API report data.';

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
            $this->error((string) ($result['message'] ?? 'FC inventory sync failed.'));
            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'FC inventory sync complete.'));
        $this->line('Report ID: ' . (string) ($result['report_id'] ?? 'n/a'));
        if (!empty($result['report_type_used'])) {
            $this->line('Report type used: ' . (string) $result['report_type_used']);
        }
        $this->line('Rows upserted: ' . (int) ($result['rows'] ?? 0));
        $this->line('Rows parsed: ' . (int) ($result['rows_parsed'] ?? 0));
        $this->line('Location rows upserted: ' . (int) ($result['location_rows_upserted'] ?? 0));

        return self::SUCCESS;
    }
}
