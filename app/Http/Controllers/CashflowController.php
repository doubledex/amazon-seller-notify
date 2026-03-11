<?php

namespace App\Http\Controllers;

use App\Models\Marketplace;
use App\Services\CashflowProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class CashflowController extends Controller
{

    public function page(): View
    {
        $marketplaces = Marketplace::query()
            ->orderBy('country_code')
            ->orderBy('name')
            ->get(['id', 'name', 'country_code'])
            ->map(function ($m) {
                $country = strtoupper(trim((string) ($m->country_code ?? '')));
                return [
                    'id' => (string) $m->id,
                    'name' => (string) ($m->name ?? ''),
                    'country_code' => $country,
                    'flag' => $this->flagFromCountryCode($country),
                ];
            })
            ->values()
            ->all();

        return view('cashflow.index', [
            'marketplaces' => $marketplaces,
        ]);
    }

    public function index(Request $request, CashflowProjectionService $service): JsonResponse
    {
        $view = strtolower(trim((string) $request->query('view', 'outstanding')));
        $dateInput = trim((string) $request->query('date', ''));
        $fromInput = trim((string) $request->query('from', ''));
        $toInput = trim((string) $request->query('to', ''));

        try {
            $date = $dateInput !== '' ? Carbon::parse($dateInput)->utc() : now()->utc();
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Invalid date parameter. Use an ISO-8601 date/time.',
            ], 422);
        }

        try {
            $fromDate = $fromInput !== '' ? Carbon::parse($fromInput)->utc() : null;
            $toDate = $toInput !== '' ? Carbon::parse($toInput)->utc() : null;
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Invalid from/to date parameters. Use date values.',
            ], 422);
        }

        $filters = [
            'marketplace_id' => $request->query('marketplace_id'),
            'region' => $request->query('region'),
            'currency' => $request->query('currency'),
        ];

        $data = match ($view) {
            'week' => $service->forWeek($date, $filters),
            'today', 'today_timing' => $service->todayTimingByMarketplace($date, $filters),
            'outstanding' => $service->outstandingByMaturity($fromDate, $toDate, $filters),
            default => $service->forDay($date, $filters),
        };

        return response()->json([
            'source' => 'amazon_order_fee_lines_v2',
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'filters' => array_filter($filters, fn ($v) => $v !== null && trim((string) $v) !== ''),
            'data' => $data,
        ]);
    }

    public function syncNow(): JsonResponse
    {
        try {
            Artisan::queue('cashflow:sync-outstanding');

            return response()->json([
                'status' => 'queued',
                'message' => 'Cashflow outstanding sync queued.',
                'queued_at_utc' => now()->utc()->toIso8601String(),
            ], 202);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Failed to queue cashflow sync.',
            ], 500);
        }
    }

    private function flagFromCountryCode(string $countryCode): string
    {
        if (strlen($countryCode) !== 2 || !ctype_alpha($countryCode)) {
            return '';
        }

        if (function_exists('mb_chr')) {
            $first = 0x1F1E6 + (ord($countryCode[0]) - 65);
            $second = 0x1F1E6 + (ord($countryCode[1]) - 65);
            return mb_chr($first, 'UTF-8') . mb_chr($second, 'UTF-8');
        }

        return '';
    }
}
