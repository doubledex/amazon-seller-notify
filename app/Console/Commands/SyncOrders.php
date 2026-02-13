<?php

namespace App\Console\Commands;

use App\Services\OrderSyncService;
use Illuminate\Console\Command;

class SyncOrders extends Command
{
    protected $signature = 'orders:sync {--days=7} {--max-pages=5} {--items-limit=50} {--address-limit=50} {--end-before=}';
    protected $description = 'Sync recent orders and persist them locally.';

    public function handle(): int
    {
        $service = new OrderSyncService();
        $result = $service->sync(
            (int) $this->option('days'),
            $this->option('end-before'),
            (int) $this->option('max-pages'),
            (int) $this->option('items-limit'),
            (int) $this->option('address-limit')
        );

        if (!$result['ok']) {
            $this->error($result['message']);
            return Command::FAILURE;
        }

        $this->info($result['message']);
        return Command::SUCCESS;
    }
}
