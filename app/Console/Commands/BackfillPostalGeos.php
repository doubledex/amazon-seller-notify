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

        $rows = Order::query()
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("upper($countryExpr) as country")
            ->selectRaw("upper($postalExpr) as postal")
            ->whereRaw("($countryExpr is not null and trim($countryExpr) != '' and $postalExpr is not null and trim($postalExpr) != '')")
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

        $geocoder = new PostalGeocoder();
        $geocoded = 0;
        $skipped = 0;

        foreach ($unique as $key => $entry) {
            if ($existing->has($key)) {
                $skipped++;
                continue;
            }
            if ($geocoded >= $limit) {
                break;
            }
            $result = $geocoder->geocode($entry['country'], $entry['postal']);
            if ($result) {
                PostalCodeGeo::updateOrCreate(
                    ['country_code' => $entry['country'], 'postal_code' => $entry['postal']],
                    ['lat' => $result['lat'], 'lng' => $result['lng'], 'source' => $result['source'] ?? null]
                );
                $geocoded++;
            }
        }

        $this->info("Geocoded: {$geocoded}, skipped: {$skipped}");
        return Command::SUCCESS;
    }
}
