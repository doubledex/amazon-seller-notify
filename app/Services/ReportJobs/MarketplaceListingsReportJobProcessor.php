<?php

namespace App\Services\ReportJobs;

use App\Models\MarketplaceListing;
use App\Models\ReportJob;

class MarketplaceListingsReportJobProcessor implements ReportJobProcessor
{
    public function process(ReportJob $job, array $rows): array
    {
        $synced = 0;
        $now = now();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sku = $this->pick($row, ['seller-sku', 'seller_sku', 'sku']);
            if ($sku === '') {
                continue;
            }

            $asin = $this->pick($row, ['asin1', 'asin', 'asin-1', 'product-id']);
            $itemName = $this->pick($row, ['item-name', 'item_name', 'title']);
            $status = $this->pick($row, ['status', 'listing-status', 'listing_status', 'add-delete', 'add_delete']);
            $quantityRaw = $this->pick($row, ['quantity', 'pending-quantity', 'fulfillment-channel-quantity']);
            $parentage = strtolower($this->pick($row, ['parent-child', 'parent_child', 'parentage', 'relationship-type']));
            $isParent = in_array($parentage, ['parent', 'variation_parent'], true);
            $quantity = is_numeric($quantityRaw) ? (int) $quantityRaw : null;

            MarketplaceListing::updateOrCreate(
                [
                    'marketplace_id' => (string) $job->marketplace_id,
                    'seller_sku' => $sku,
                ],
                [
                    'asin' => $asin !== '' ? $asin : null,
                    'item_name' => $itemName !== '' ? $itemName : null,
                    'listing_status' => $status !== '' ? $status : null,
                    'quantity' => $quantity,
                    'parentage' => $parentage !== '' ? $parentage : null,
                    'is_parent' => $isParent,
                    'source_report_id' => $job->external_report_id,
                    'last_seen_at' => $now,
                    'raw_listing' => $row,
                ]
            );

            $synced++;
        }

        return [
            'rows_ingested' => $synced,
        ];
    }

    private function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }

            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
