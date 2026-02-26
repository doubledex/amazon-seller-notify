<?php

namespace App\Console\Commands;

use App\Models\CityGeo;
use App\Models\Order;
use App\Services\PostalGeocoder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BackfillCityGeos extends Command
{
    protected $signature = 'map:geocode-missing-cities {--limit=250} {--older-than-days=14} {--statuses=Shipped,Canceled,Unfulfillable} {--show-failures=20}';
    protected $description = 'Backfill persistent city geocodes for older, stable orders missing postal geocodes.';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 2000));
        $olderThanDays = max(0, min((int) $this->option('older-than-days'), 3650));
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('statuses')))));
        if (empty($statuses)) {
            $statuses = ['Shipped', 'Canceled', 'Unfulfillable'];
        }

        $cutoff = Carbon::now()->subDays($olderThanDays)->endOfDay();

        $countryExpr = "coalesce(osa.country_code, orders.shipping_country_code)";
        $postalExpr = "coalesce(osa.postal_code, orders.shipping_postal_code)";
        $cityExpr = "coalesce(osa.city, orders.shipping_city)";
        $regionExpr = "coalesce(osa.region, orders.shipping_region)";

        $rows = Order::query()
            ->leftJoin('order_ship_addresses as osa', 'osa.order_id', '=', 'orders.amazon_order_id')
            ->selectRaw("upper($countryExpr) as country")
            ->selectRaw("min($cityExpr) as city")
            ->selectRaw("min($regionExpr) as region")
            ->selectRaw('count(*) as order_count')
            ->whereDate('orders.purchase_date', '<=', $cutoff->toDateString())
            ->whereIn('orders.order_status', $statuses)
            ->whereRaw("($countryExpr is not null and trim($countryExpr) != '' and $cityExpr is not null and trim($cityExpr) != '')")
            ->whereRaw("($postalExpr is null or trim($postalExpr) = '')")
            ->groupBy(DB::raw("upper($countryExpr)"), DB::raw("upper($cityExpr)"), DB::raw("upper($regionExpr)"))
            ->orderByDesc('order_count')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No eligible city groups found for geocoding.');
            return Command::SUCCESS;
        }

        $entries = [];
        foreach ($rows as $row) {
            $country = CityGeo::normalizeCountry((string) ($row->country ?? ''));
            $city = trim((string) ($row->city ?? ''));
            $region = trim((string) ($row->region ?? ''));
            if ($country === '' || $city === '') {
                continue;
            }

            $hash = CityGeo::lookupHash($country, $city, $region);
            $entries[$hash] = [
                'country' => $country,
                'city' => $city,
                'region' => $region,
                'hash' => $hash,
            ];
        }

        if (empty($entries)) {
            $this->warn('No valid city groups remained after normalization.');
            return Command::SUCCESS;
        }

        $existingHashes = CityGeo::query()
            ->whereIn('lookup_hash', array_keys($entries))
            ->pluck('lookup_hash')
            ->all();
        $existing = array_fill_keys($existingHashes, true);

        $geocoder = new PostalGeocoder();
        $processed = 0;
        $created = 0;
        $skippedExisting = 0;
        $failed = 0;
        $failedEntries = [];

        foreach ($entries as $hash => $entry) {
            if (isset($existing[$hash])) {
                $skippedExisting++;
                continue;
            }

            if ($processed >= $limit) {
                break;
            }

            $result = $geocoder->geocodeCity($entry['country'], $entry['city'], $entry['region'] !== '' ? $entry['region'] : null);
            $processed++;

            if (!$result) {
                $failed++;
                $failedEntries[] = $entry;
                continue;
            }

            CityGeo::updateOrCreate(
                ['lookup_hash' => $hash],
                [
                    'country_code' => $entry['country'],
                    'city' => CityGeo::normalizeCity($entry['city']),
                    'region' => CityGeo::normalizeRegion($entry['region']),
                    'lat' => $result['lat'],
                    'lng' => $result['lng'],
                    'source' => $result['source'] ?? null,
                    'last_used_at' => now(),
                ]
            );
            $created++;
        }

        $this->info("City geocode backfill complete. created={$created} failed={$failed} skipped_existing={$skippedExisting} attempted={$processed}");
        $this->line('Eligible city groups: ' . count($entries));
        $this->line("Existing city geocodes in-scope: {$skippedExisting}");

        $showFailures = max(0, min((int) $this->option('show-failures'), 200));
        if ($failed > 0 && $showFailures > 0) {
            $this->warn("Failed city lookups (showing up to {$showFailures}):");
            foreach (array_slice($failedEntries, 0, $showFailures) as $entry) {
                $region = trim((string) ($entry['region'] ?? ''));
                $this->line(
                    '- ' . $entry['country']
                    . ' | ' . $entry['city']
                    . ($region !== '' ? " | {$region}" : ' | (no region)')
                );
            }
        }

        return Command::SUCCESS;
    }
}
