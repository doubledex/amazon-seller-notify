<?php

namespace App\Console\Commands;

use App\Models\Mcu;
use App\Models\McuIdentifier;
use Illuminate\Console\Command;

class CleanupMcuSkuBarcodes extends Command
{
    protected $signature = 'mcus:cleanup-sku-barcodes
        {--apply : Persist updates/deletes (default is dry-run)}
        {--limit=0 : Optional max MCU records to evaluate}';

    protected $description = 'Remove barcode values/identifier rows that were auto-copied from seller SKU.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $query = Mcu::query()
            ->with([
                'sellableUnits:id,mcu_id,barcode',
                'identifiers:id,mcu_id,identifier_type,identifier_value,channel,marketplace,region,is_projection_identifier',
                'marketplaceProjections:id,mcu_id,seller_sku',
            ])
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $mcus = $query->get();
        $evaluated = 0;
        $barcodeClears = 0;
        $identifierDeletes = 0;

        $previewRows = [];

        foreach ($mcus as $mcu) {
            $evaluated++;
            $sellerSkus = $this->sellerSkuSet($mcu);
            if (empty($sellerSkus)) {
                continue;
            }

            $sellableUnit = $mcu->sellableUnits->first();
            $barcode = trim((string) ($sellableUnit?->barcode ?? ''));
            $clearBarcode = $barcode !== '' && in_array($barcode, $sellerSkus, true);

            $barcodeIdentifierIds = $mcu->identifiers
                ->filter(function ($identifier) use ($sellerSkus) {
                    if ($identifier->identifier_type !== 'barcode') {
                        return false;
                    }

                    if ((bool) $identifier->is_projection_identifier) {
                        return false;
                    }

                    if (
                        trim((string) $identifier->channel) !== '' ||
                        trim((string) $identifier->marketplace) !== '' ||
                        trim((string) $identifier->region) !== ''
                    ) {
                        return false;
                    }

                    return in_array(trim((string) $identifier->identifier_value), $sellerSkus, true);
                })
                ->pluck('id')
                ->all();

            if (!$clearBarcode && empty($barcodeIdentifierIds)) {
                continue;
            }

            $previewRows[] = [
                'mcu_id' => (string) $mcu->id,
                'barcode_before' => $barcode !== '' ? $barcode : '-',
                'clear_barcode' => $clearBarcode ? 'yes' : 'no',
                'delete_identifier_ids' => empty($barcodeIdentifierIds) ? '-' : implode(',', $barcodeIdentifierIds),
            ];

            if (!$apply) {
                continue;
            }

            if ($clearBarcode && $sellableUnit) {
                $sellableUnit->update(['barcode' => null]);
                $barcodeClears++;
            }

            if (!empty($barcodeIdentifierIds)) {
                $deleted = McuIdentifier::query()->whereIn('id', $barcodeIdentifierIds)->delete();
                $identifierDeletes += $deleted;
            }
        }

        if (!empty($previewRows)) {
            $this->table(
                ['MCU', 'Barcode Before', 'Clear Barcode', 'Delete Barcode Identifier IDs'],
                $previewRows
            );
        } else {
            $this->line('No matching auto-copied barcode values found.');
        }

        if ($apply) {
            $this->info('Applied cleanup.');
            $this->line('MCUs evaluated: ' . $evaluated);
            $this->line('Sellable unit barcodes cleared: ' . $barcodeClears);
            $this->line('Barcode identifiers deleted: ' . $identifierDeletes);
        } else {
            $this->warn('Dry run only. Re-run with --apply to persist changes.');
            $this->line('MCUs evaluated: ' . $evaluated);
        }

        return self::SUCCESS;
    }

    private function sellerSkuSet(Mcu $mcu): array
    {
        $fromIdentifiers = $mcu->identifiers
            ->where('identifier_type', 'seller_sku')
            ->pluck('identifier_value')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $fromProjections = $mcu->marketplaceProjections
            ->pluck('seller_sku')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        return array_values(array_unique(array_merge($fromIdentifiers, $fromProjections)));
    }
}
