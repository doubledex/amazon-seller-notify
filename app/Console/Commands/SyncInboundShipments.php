<?php

namespace App\Console\Commands;

use App\Services\Amazon\Inbound\InboundShipmentSyncService;
use Illuminate\Console\Command;

class SyncInboundShipments extends Command
{
    protected $signature = 'inbound:sync-shipments
        {--days=120 : Days of shipment updates to fetch}
        {--region= : Optional SP-API region (EU|NA|FE)}
        {--marketplace= : Optional marketplace ID}
        {--no-detect : Skip discrepancy detection after sync}
        {--debug : Enable verbose inbound plan/shipment discovery logs}';

    protected $description = 'Sync inbound shipments/items via official Amazon SP-API and populate local discrepancy source tables.';

    public function handle(InboundShipmentSyncService $service): int
    {
        $result = $service->sync(
            days: (int) $this->option('days'),
            region: $this->option('region') ? (string) $this->option('region') : null,
            marketplaceId: $this->option('marketplace') ? (string) $this->option('marketplace') : null,
            runDetection: !(bool) $this->option('no-detect'),
            debug: (bool) $this->option('debug'),
        );

        if (!($result['ok'] ?? false)) {
            $this->error((string) ($result['message'] ?? 'Inbound shipment sync failed.'));
            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'Inbound shipment sync complete.'));
        $this->line('Marketplaces scanned: ' . (int) ($result['marketplaces_scanned'] ?? 0));
        $this->line('Shipments scanned: ' . (int) ($result['shipments_scanned'] ?? 0));
        $this->line('Shipments upserted: ' . (int) ($result['shipments_upserted'] ?? 0));
        $this->line('Shipment items scanned: ' . (int) ($result['shipment_items_scanned'] ?? 0));
        $this->line('Carton rows upserted: ' . (int) ($result['carton_rows_upserted'] ?? 0));
        $this->line('Discrepancies upserted: ' . (int) ($result['discrepancies_upserted'] ?? 0));

        return self::SUCCESS;
    }
}
