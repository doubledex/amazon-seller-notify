<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceListing;
use App\Models\Marketplace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class AsinController extends Controller
{
    private const EUROPEAN_COUNTRY_CODES = [
        'BE',
        'DE',
        'ES',
        'FR',
        'GB',
        'IE',
        'IT',
        'NL',
        'PL',
        'SE',
    ];

    public function index(Request $request): View|StreamedResponse
    {
        $statusFilter = strtolower((string) $request->query('status', 'all'));
        if (!in_array($statusFilter, ['all', 'active', 'inactive', 'unknown'], true)) {
            $statusFilter = 'all';
        }
        $search = trim((string) $request->query('q', ''));

        $marketplaces = Marketplace::query()
            ->whereIn('country_code', self::EUROPEAN_COUNTRY_CODES)
            ->orderBy('country_code')
            ->orderBy('name')
            ->get();

        $marketplaceIds = $marketplaces->pluck('id')->values()->all();
        $listingsByMarketplace = empty($marketplaceIds)
            ? collect()
            : MarketplaceListing::query()
                ->whereIn('marketplace_id', $marketplaceIds)
                ->orderBy('marketplace_id')
                ->orderBy('asin')
                ->orderBy('seller_sku')
                ->get()
                ->groupBy('marketplace_id');

        $marketplaceAsins = $marketplaces->map(function (Marketplace $marketplace) use ($listingsByMarketplace) {
            $rows = $listingsByMarketplace->get($marketplace->id, collect())->map(function (MarketplaceListing $listing) {
                $status = $this->normalizeListingStatus($listing->listing_status, $listing->quantity);

                return [
                    'asin' => $listing->asin ?? '',
                    'order_count' => 0,
                    'item_count' => 0,
                    'sku_count' => 1,
                    'skus' => [$listing->seller_sku],
                    'listing_status' => $status,
                    'status_updated_at' => optional($listing->last_seen_at)?->toDateTimeString(),
                    'seller_sku' => $listing->seller_sku,
                    'item_name' => $listing->item_name ?? '',
                    'quantity' => $listing->quantity,
                    'is_parent' => (bool) $listing->is_parent,
                    'raw_status' => $listing->listing_status ?? '',
                ];
            })->values();

            return $this->summarizeMarketplace($marketplace, $rows);
        })->values();

        $marketplaceAsins = $marketplaceAsins->map(function (array $marketplace) use ($statusFilter, $search) {
            $rows = $marketplace['asins'];

            if ($statusFilter !== 'all') {
                $targetStatus = ucfirst($statusFilter);
                $rows = $rows->filter(fn ($row) => ($row['listing_status'] ?? 'Unknown') === $targetStatus)->values();
            }

            if ($search !== '') {
                $needle = mb_strtolower($search);
                $rows = $rows->filter(function ($row) use ($needle) {
                    $asin = mb_strtolower((string) ($row['asin'] ?? ''));
                    if (str_contains($asin, $needle)) {
                        return true;
                    }

                    foreach (($row['skus'] ?? []) as $sku) {
                        if (str_contains(mb_strtolower((string) $sku), $needle)) {
                            return true;
                        }
                    }

                    $itemName = mb_strtolower((string) ($row['item_name'] ?? ''));
                    if (str_contains($itemName, $needle)) {
                        return true;
                    }

                    return false;
                })->values();
            }

            $marketplace['asins'] = $rows->values();
            $marketplace['listing_count'] = $rows->count();
            $marketplace['unique_asin_count'] = $rows->pluck('asin')->filter()->unique()->count();
            $marketplace['active_count'] = $rows->where('listing_status', 'Active')->count();
            $marketplace['inactive_count'] = $rows->where('listing_status', 'Inactive')->count();
            $marketplace['unknown_count'] = $rows->where('listing_status', 'Unknown')->count();
            $marketplace['parent_count'] = $rows->where('is_parent', true)->count();
            $marketplace['child_count'] = $rows->where('is_parent', false)->count();
            $marketplace['total_item_count'] = $rows->sum(fn ($row) => (int) $row['item_count']);
            $marketplace['total_order_count'] = $rows->sum(fn ($row) => (int) $row['order_count']);

            return $marketplace;
        })->filter(fn (array $marketplace) => $marketplace['listing_count'] > 0)->values();

        if ($request->query('export') === 'csv') {
            return $this->exportCsv($marketplaceAsins);
        }

        return view('asins.index', [
            'marketplaceAsins' => $marketplaceAsins,
            'europeanCountryCodes' => self::EUROPEAN_COUNTRY_CODES,
            'statusFilter' => $statusFilter,
            'search' => $search,
        ]);
    }

    private function summarizeMarketplace(Marketplace $marketplace, $rows): array
    {
        return [
            'id' => $marketplace->id,
            'name' => $marketplace->name ?: 'Unknown',
            'country_code' => $marketplace->country_code ?: 'N/A',
            'asins' => $rows,
            'listing_count' => $rows->count(),
            'unique_asin_count' => $rows->pluck('asin')->filter()->unique()->count(),
            'active_count' => $rows->where('listing_status', 'Active')->count(),
            'inactive_count' => $rows->where('listing_status', 'Inactive')->count(),
            'unknown_count' => $rows->where('listing_status', 'Unknown')->count(),
            'parent_count' => $rows->where('is_parent', true)->count(),
            'child_count' => $rows->where('is_parent', false)->count(),
            'total_item_count' => 0,
            'total_order_count' => 0,
        ];
    }

    private function normalizeListingStatus(?string $status, ?int $quantity): string
    {
        $raw = strtolower(trim((string) $status));
        if ($raw === '') {
            return ($quantity !== null && $quantity > 0) ? 'Active' : 'Unknown';
        }

        if (in_array($raw, ['active', 'open', 'available', 'a', 'y', 'add', 'new'], true)) {
            return 'Active';
        }

        if (in_array($raw, ['inactive', 'closed', 'deleted', 'delete', 'x', 'd', 'n'], true)) {
            return 'Inactive';
        }

        return 'Unknown';
    }

    private function exportCsv($marketplaceAsins): StreamedResponse
    {
        $fileName = 'europe-asins-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($marketplaceAsins) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'marketplace_id',
                'marketplace_country_code',
                'marketplace_name',
                'asin',
                'seller_sku',
                'is_parent',
                'item_name',
                'listing_status',
                'raw_status',
                'quantity',
                'status_updated_at',
            ]);

            foreach ($marketplaceAsins as $marketplace) {
                foreach ($marketplace['asins'] as $asinRow) {
                    fputcsv($out, [
                        $marketplace['id'],
                        $marketplace['country_code'],
                        $marketplace['name'],
                        $asinRow['asin'],
                        $asinRow['seller_sku'] ?? '',
                        !empty($asinRow['is_parent']) ? 'parent' : 'child',
                        $asinRow['item_name'] ?? '',
                        $asinRow['listing_status'],
                        $asinRow['raw_status'] ?? '',
                        $asinRow['quantity'] ?? '',
                        $asinRow['status_updated_at'] ?? '',
                    ]);
                }
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
