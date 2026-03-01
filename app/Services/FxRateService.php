<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FxRateService
{
    public function convert(float $amount, string $from, string $to, string $date): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '') {
            return null;
        }
        if ($from === $to) {
            return $amount;
        }

        $rate = $this->getRate($from, $to, $date);
        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }

    public function getRate(string $from, string $to, string $date): ?float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        $dateCacheKey = "fx_rate:{$date}:{$from}:{$to}";
        $pairCacheKey = "fx_rate_pair:{$from}:{$to}";

        $cachedDateRate = Cache::get($dateCacheKey);
        if (is_numeric($cachedDateRate) && (float) $cachedDateRate > 0) {
            return (float) $cachedDateRate;
        }

        // Fast path: reuse a nearby pair rate only when it is within 1 day.
        $cachedPairRate = Cache::get($pairCacheKey);
        if (is_array($cachedPairRate)) {
            $pairRate = (float) ($cachedPairRate['rate'] ?? 0);
            $pairDate = trim((string) ($cachedPairRate['as_of_date'] ?? ''));
            if ($pairRate > 0 && $pairDate !== '') {
                try {
                    $requestDate = Carbon::parse($date)->startOfDay();
                    $rateDate = Carbon::parse($pairDate)->startOfDay();
                    if (abs($requestDate->diffInDays($rateDate, false)) <= 1) {
                        Cache::put($dateCacheKey, $pairRate, now()->addDays(7));
                        return $pairRate;
                    }
                } catch (\Throwable) {
                    // Ignore parse failures and continue to API call.
                }
            }
        }

        try {
            $response = Http::timeout(10)
                ->get("https://api.frankfurter.app/{$date}", [
                    'from' => $from,
                    'to' => $to,
                ]);

            if (!$response->ok()) {
                Log::warning('FX rate request failed', [
                    'status' => $response->status(),
                    'from' => $from,
                    'to' => $to,
                    'date' => $date,
                ]);
                return null;
            }

            $rate = (float) data_get($response->json(), "rates.{$to}");
            if ($rate <= 0) {
                return null;
            }

            Cache::put($dateCacheKey, $rate, now()->addDays(7));
            Cache::put($pairCacheKey, [
                'rate' => $rate,
                'as_of_date' => $date,
            ], now()->addDays(7));

            return $rate;
        } catch (\Throwable $e) {
            Log::warning('FX rate request exception', [
                'from' => $from,
                'to' => $to,
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
