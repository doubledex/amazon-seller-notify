<?php

namespace App\Console\Commands;

use App\Models\PostalCodeGeo;
use App\Models\Order;
use App\Services\PostalGeocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPostalGeos extends Command
{
    protected $signature = 'map:geocode-missing {--limit=250}';
    protected $description = 'Backfill missing postal code geocodes from cached orders.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $limit = max(1, min($limit, 2000));

        $countryExpr = "coalesce(osa.country_code, orders.shipping_country_code)";
        $postalExpr = "coalesce(osa.postal_code, orders.shipping_postal_code)";
        $requiredAddressExpr = "($countryExpr is not null and trim($countryExpr) != '' and $postalExpr is not null and trim($postalExpr) != '')";

        $totalOrders = (int) Order::query()->count();
        $ordersWithCountryPostal = $this->countOrdersWithCountryPostal($requiredAddressExpr);
        $ordersMissingCountryPostal = max(0, $totalOrders - $ordersWithCountryPostal);
        $ordersGeocodedBefore = $this->countOrdersWithGeocode($countryExpr, $postalExpr, $requiredAddressExpr);
        $ordersPendingBefore = max(0, $ordersWithCountryPostal - $ordersGeocodedBefore);

        $rows = Order::query()
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("upper($countryExpr) as country")
            ->selectRaw("upper($postalExpr) as postal")
            ->whereRaw($requiredAddressExpr)
            ->groupBy(DB::raw("upper($countryExpr)"), DB::raw("upper($postalExpr)"))
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No orders with country/postal data found in the database.');
            return Command::FAILURE;
        }

        $unique = [];
        foreach ($rows as $row) {
            $country = (string) ($row->country ?? '');
            $postal = (string) ($row->postal ?? '');
            if ($country === '' || $postal === '') {
                continue;
            }
            $unique[$country . '|' . $postal] = ['country' => $country, 'postal' => $postal];
        }

        $countryGroups = [];
        foreach ($unique as $entry) {
            $countryGroups[$entry['country']][] = $entry['postal'];
        }

        $existing = $this->existingPostalGeoKeys($countryGroups);

        $geocoder = new PostalGeocoder();
        $geocoded = 0;
        $skipped = 0;
        $attempted = 0;
        $failed = 0;

        foreach ($unique as $key => $entry) {
            if ($existing->has($key)) {
                $skipped++;
                continue;
            }
            if ($geocoded >= $limit) {
                break;
            }
            $attempted++;
            $result = $geocoder->geocode($entry['country'], $entry['postal']);
            if ($result) {
                PostalCodeGeo::updateOrCreate(
                    ['country_code' => $entry['country'], 'postal_code' => $entry['postal']],
                    ['lat' => $result['lat'], 'lng' => $result['lng'], 'source' => $result['source'] ?? null]
                );
                $geocoded++;
            } else {
                $failed++;
            }
        }

        $existingAfter = $this->existingPostalGeoKeys($countryGroups);
        $uniqueTotal = count($unique);
        $uniqueResolvedAfter = (int) $existingAfter->count();
        $uniqueRemaining = max(0, $uniqueTotal - $uniqueResolvedAfter);

        $ordersGeocodedAfter = $this->countOrdersWithGeocode($countryExpr, $postalExpr, $requiredAddressExpr);
        $ordersPendingAfter = max(0, $ordersWithCountryPostal - $ordersGeocodedAfter);
        $ordersRemainingEmpty = $ordersMissingCountryPostal + $ordersPendingAfter;

        $this->info("Geocoded: {$geocoded}, skipped: {$skipped}");
        $this->line("Attempts: {$attempted}, failed lookups: {$failed}, limit: {$limit}");
        $this->line("Orders: total {$totalOrders}, with country+postal {$ordersWithCountryPostal}, missing country/postal {$ordersMissingCountryPostal}");
        $this->line("Orders geocoded: before {$ordersGeocodedBefore}, after {$ordersGeocodedAfter}, remaining with missing geocode {$ordersPendingAfter}");
        $this->line("Orders remaining empty overall: {$ordersRemainingEmpty}");
        $this->line("Unique postals: total {$uniqueTotal}, resolved {$uniqueResolvedAfter}, remaining {$uniqueRemaining}");

        return Command::SUCCESS;
    }

    private function existingPostalGeoKeys(array $countryGroups)
    {
        $existing = collect();
        foreach ($countryGroups as $country => $postals) {
            $rows = PostalCodeGeo::query()
                ->where('country_code', $country)
                ->whereIn('postal_code', $postals)
                ->get();
            foreach ($rows as $row) {
                $existing->put($row->country_code . '|' . $row->postal_code, true);
            }
        }

        return $existing;
    }

    private function countOrdersWithCountryPostal(string $requiredAddressExpr): int
    {
        return (int) Order::query()
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id')
            ->whereRaw($requiredAddressExpr)
            ->distinct('orders.amazon_order_id')
            ->count('orders.amazon_order_id');
    }

    private function countOrdersWithGeocode(string $countryExpr, string $postalExpr, string $requiredAddressExpr): int
    {
        return (int) Order::query()
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id')
            ->leftJoin('postal_code_geos as pcg', function ($join) use ($countryExpr, $postalExpr) {
                $join->whereRaw("upper($countryExpr) = upper(pcg.country_code)")
                    ->whereRaw("upper($postalExpr) = upper(pcg.postal_code)");
            })
            ->whereRaw($requiredAddressExpr)
            ->whereNotNull('pcg.id')
            ->distinct('orders.amazon_order_id')
            ->count('orders.amazon_order_id');
    }
}
