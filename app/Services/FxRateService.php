<?php

namespace App\Services;

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
        $cacheKey = "fx_rate:{$date}:{$from}:{$to}";

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($from, $to, $date) {
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
                return $rate > 0 ? $rate : null;
            } catch (\Throwable $e) {
                Log::warning('FX rate request exception', [
                    'from' => $from,
                    'to' => $to,
                    'date' => $date,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        });
    }
}

