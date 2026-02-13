<?php

namespace App\Jobs;

use App\Services\OrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $days;
    public ?string $endBefore;
    public int $maxPages;
    public int $itemsLimit;
    public int $addressLimit;

    public function __construct(
        int $days = 7,
        ?string $endBefore = null,
        int $maxPages = 5,
        int $itemsLimit = 50,
        int $addressLimit = 50
    ) {
        $this->days = $days;
        $this->endBefore = $endBefore;
        $this->maxPages = $maxPages;
        $this->itemsLimit = $itemsLimit;
        $this->addressLimit = $addressLimit;
    }

    public function handle(OrderSyncService $service): void
    {
        $service->sync($this->days, $this->endBefore, $this->maxPages, $this->itemsLimit, $this->addressLimit);
    }
}
