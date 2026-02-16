<?php

namespace App\Services;

use App\Models\Marketplace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketplaceHolidayService
{
    public function holidaysWindow(int $lookbackDays = 7, int $aheadDays = 30): array
    {
        $lookbackDays = max(0, min($lookbackDays, 30));
        $aheadDays = max(1, min($aheadDays, 120));
        $from = Carbon::now()->subDays($lookbackDays)->startOfDay();
        $to = Carbon::now()->addDays($aheadDays)->endOfDay();

        $cacheKey = sprintf(
            'dashboard_holidays_%s_%s_%d_%d',
            $from->toDateString(),
            $to->toDateString(),
            $lookbackDays,
            $aheadDays
        );

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($from, $to) {
            return $this->buildHolidayPayload($from, $to);
        });
    }

    private function buildHolidayPayload(Carbon $from, Carbon $to): array
    {
        $marketplaces = Marketplace::query()
            ->select(['id', 'name', 'country_code'])
            ->whereNotNull('country_code')
            ->get();

        $countryToMarketplaces = [];
        foreach ($marketplaces as $marketplace) {
            $country = $this->normalizeCountry((string) $marketplace->country_code);
            if ($country === '') {
                continue;
            }

            $countryToMarketplaces[$country][] = [
                'id' => (string) $marketplace->id,
                'name' => (string) ($marketplace->name ?? $marketplace->id),
            ];
        }

        if (empty($countryToMarketplaces)) {
            return [
                'items' => [],
                'countries' => [],
                'errors' => [],
            ];
        }

        $years = array_values(array_unique([$from->year, $to->year]));
        $items = [];
        $errors = [];

        foreach (array_keys($countryToMarketplaces) as $country) {
            foreach ($years as $year) {
                $response = Http::timeout(15)
                    ->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/{$country}");

                if (!$response->ok()) {
                    $errors[] = [
                        'country' => $country,
                        'year' => $year,
                        'status' => $response->status(),
                    ];
                    continue;
                }

                $rows = $response->json();
                if (!is_array($rows)) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $date = trim((string) ($row['date'] ?? ''));
                    if ($date === '') {
                        continue;
                    }

                    try {
                        $holidayDate = Carbon::parse($date)->startOfDay();
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if ($holidayDate->lt($from) || $holidayDate->gt($to)) {
                        continue;
                    }

                    $types = $row['types'] ?? [];
                    if (!is_array($types)) {
                        $types = [];
                    }

                    $items[] = [
                        'date' => $holidayDate->toDateString(),
                        'country' => $country,
                        'name' => (string) ($row['name'] ?? ''),
                        'local_name' => (string) ($row['localName'] ?? ''),
                        'types' => $types,
                        'marketplaces' => $countryToMarketplaces[$country] ?? [],
                    ];
                }
            }
        }

        usort($items, function (array $a, array $b) {
            $dateCmp = strcmp($a['date'], $b['date']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            return strcmp($a['country'], $b['country']);
        });

        if (!empty($errors)) {
            Log::warning('Holiday API fetch had partial failures', ['errors' => $errors]);
        }

        return [
            'items' => $items,
            'countries' => array_keys($countryToMarketplaces),
            'errors' => $errors,
        ];
    }

    private function normalizeCountry(string $country): string
    {
        $country = strtoupper(trim($country));
        if ($country === 'UK') {
            return 'GB';
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            return '';
        }
        return $country;
    }
}
