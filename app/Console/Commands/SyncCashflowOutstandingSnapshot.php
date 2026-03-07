<?php

namespace App\Console\Commands;

use App\Services\CashflowProjectionService;
use Illuminate\Console\Command;

class SyncCashflowOutstandingSnapshot extends Command
{
    protected $signature = 'cashflow:sync-outstanding';
    protected $description = 'Sync outstanding cashflow snapshot from Finances API transactions (rolling 130 days).';

    public function handle(CashflowProjectionService $service): int
    {
        $summary = $service->syncOutstandingSnapshot();

        $this->info(sprintf(
            'Outstanding snapshot synced. Rows written: %d, transactions seen: %d, regions: %d.',
            (int) ($summary['rows_written'] ?? 0),
            (int) ($summary['transactions_seen'] ?? 0),
            (int) ($summary['regions'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
