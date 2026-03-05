<?php

namespace App\Http\Controllers;

use App\Services\CashflowProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CashflowController extends Controller
{
    public function index(Request $request, CashflowProjectionService $service): JsonResponse
    {
        $view = strtolower(trim((string) $request->query('view', 'day')));
        $dateInput = trim((string) $request->query('date', ''));

        try {
            $date = $dateInput !== '' ? Carbon::parse($dateInput)->utc() : now()->utc();
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Invalid date parameter. Use an ISO-8601 date/time.',
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
            default => $service->forDay($date, $filters),
        };

        return response()->json([
            'source' => 'amazon_order_fee_lines_v2',
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'filters' => array_filter($filters, fn ($v) => $v !== null && trim((string) $v) !== ''),
            'data' => $data,
        ]);
    }
}
