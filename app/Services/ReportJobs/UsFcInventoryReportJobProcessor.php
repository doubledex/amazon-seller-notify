<?php

namespace App\Services\ReportJobs;

use App\Models\ReportJob;
use App\Models\UsFcInventory;

class UsFcInventoryReportJobProcessor implements ReportJobProcessor
{
    public function process(ReportJob $job, array $rows): array
    {
        if (strtoupper(trim((string) $job->report_type)) !== 'GET_LEDGER_SUMMARY_VIEW_DATA') {
            return [
                'rows_ingested' => 0,
                'rows_missing_fc' => 0,
                'rows_missing_sku' => 0,
                'sample_row_keys' => [],
            ];
        }

        $latestDate = $this->latestRowDate($rows);
        $upsertRows = [];
        $missingFcRows = 0;
        $missingSkuRows = 0;
        $sampleKeys = [];
        $now = now();
        $reportDate = $latestDate ?? $job->completed_at?->toDateString();

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($latestDate !== null) {
                $rowDate = $this->rowDateString($row);
                if ($rowDate === null || $rowDate !== $latestDate) {
                    continue;
                }
            }

            $normalized = $this->normalizeRow($row);
            $fc = $normalized['fulfillment_center_id'];
            $sku = $normalized['seller_sku'];
            $fnsku = $normalized['fnsku'];
            $condition = $normalized['item_condition'];

            if ($fc === '') {
                $missingFcRows++;
                if (count($sampleKeys) < 3) {
                    $sampleKeys[] = array_keys($row);
                }
                continue;
            }

            if ($sku === '' && $fnsku === '') {
                $missingSkuRows++;
                continue;
            }

            $upsertRows[] = [
                'marketplace_id' => (string) $job->marketplace_id,
                'fulfillment_center_id' => $fc,
                'seller_sku' => $sku !== '' ? $sku : null,
                'asin' => $normalized['asin'] !== '' ? $normalized['asin'] : null,
                'fnsku' => $fnsku !== '' ? $fnsku : null,
                'item_condition' => $condition !== '' ? $condition : null,
                'quantity_available' => $normalized['quantity_available'],
                'raw_row' => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'report_id' => $job->external_report_id,
                'report_type' => $job->report_type,
                'report_date' => $reportDate,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($upsertRows)) {
            UsFcInventory::upsert(
                $upsertRows,
                ['marketplace_id', 'fulfillment_center_id', 'seller_sku', 'fnsku', 'item_condition'],
                ['asin', 'quantity_available', 'raw_row', 'report_id', 'report_type', 'report_date', 'last_seen_at', 'updated_at']
            );
        }

        return [
            'rows_ingested' => count($upsertRows),
            'rows_missing_fc' => $missingFcRows,
            'rows_missing_sku' => $missingSkuRows,
            'sample_row_keys' => $sampleKeys,
        ];
    }

    private function latestRowDate(array $rows): ?string
    {
        $max = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = $this->rowDateString($row);
            if ($date === null) {
                continue;
            }
            if ($max === null || strcmp($date, $max) > 0) {
                $max = $date;
            }
        }

        return $max;
    }

    private function rowDateString(array $row): ?string
    {
        $value = '';
        foreach (['Date', 'date', 'Report Date', 'report_date'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                break;
            }
        }
        if ($value === '') {
            return null;
        }

        foreach (['m/d/Y', 'm/d/y', 'Y-m-d', 'n/j/Y', 'n/j/y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        try {
            return (new \DateTime($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeRow(array $row): array
    {
        $flat = [];
        foreach ($row as $key => $value) {
            $flat[strtolower(trim((string) $key))] = is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
        }

        $fc = $this->pick($flat, ['fulfillment-center-id', 'fulfillment_center_id', 'fulfillment center id', 'fulfillmentcenterid']);
        if ($fc === '') {
            $fc = $this->pick($flat, [
                'fulfillment center',
                'fulfillment-center',
                'fulfillment_center',
                'warehouse-id',
                'warehouse_id',
                'warehouse id',
                'warehouseid',
                'location-id',
                'location_id',
                'location id',
                'locationid',
                'location',
                'store',
                'fc',
            ]);
        }

        $sku = $this->pick($flat, ['seller-sku', 'seller_sku', 'sku', 'merchant-sku', 'merchant_sku', 'merchant sku', 'msku']);
        $asin = $this->pick($flat, ['asin']);
        $fnsku = $this->pick($flat, ['fnsku']);
        $condition = $this->pick($flat, ['condition', 'item-condition', 'item_condition', 'disposition']);
        $qtyRaw = $this->pick($flat, [
            'quantity',
            'afn-fulfillable-quantity',
            'afn_fulfillable_quantity',
            'fulfillable-quantity',
            'fulfillable_quantity',
            'total-quantity',
            'total_quantity',
            'available',
            'available_quantity',
            'sellable-quantity',
            'sellable_quantity',
            'ending warehouse balance',
            'ending_warehouse_balance',
        ]);
        $qty = is_numeric($qtyRaw) ? (int) round((float) $qtyRaw) : 0;

        return [
            'fulfillment_center_id' => strtoupper(trim($fc)),
            'seller_sku' => trim($sku),
            'asin' => strtoupper(trim($asin)),
            'fnsku' => strtoupper(trim($fnsku)),
            'item_condition' => trim($condition),
            'quantity_available' => $qty,
        ];
    }

    private function pick(array $flat, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $flat)) {
                return (string) $flat[$key];
            }
        }

        return '';
    }
}
