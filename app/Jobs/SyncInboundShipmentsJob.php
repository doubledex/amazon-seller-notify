<?php

namespace App\Jobs;

use App\Services\Amazon\Inbound\InboundShipmentSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncInboundShipmentsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $days = 120,
        public ?string $region = null,
        public ?string $marketplaceId = null,
        public bool $runDetection = true,
        public bool $debug = false,
    ) {
    }

    public function handle(InboundShipmentSyncService $service): void
    {
        $result = $service->sync(
            days: $this->days,
            region: $this->region,
            marketplaceId: $this->marketplaceId,
            runDetection: $this->runDetection,
            debug: $this->debug
        );

        if (!($result['ok'] ?? false)) {
            Log::warning('Inbound sync job completed with errors', [
                'days' => $this->days,
                'region' => $this->region,
                'marketplace_id' => $this->marketplaceId,
                'run_detection' => $this->runDetection,
                'debug' => $this->debug,
                'message' => (string) ($result['message'] ?? ''),
            ]);

            return;
        }

        Log::info('Inbound sync job completed', [
            'days' => $this->days,
            'region' => $this->region,
            'marketplace_id' => $this->marketplaceId,
            'run_detection' => $this->runDetection,
            'debug' => $this->debug,
            'message' => (string) ($result['message'] ?? ''),
        ]);
    }
}
