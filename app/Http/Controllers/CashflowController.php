<?php

namespace App\Http\Controllers;

use App\Services\CashflowProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CashflowController extends Controller
{

    public function page(): View
    {
        return view('cashflow.index');
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
            $fromDate = $fromInput !== '' ? Carbon::parse($fromInput)->utc() : now()->utc();
            $toDate = $toInput !== '' ? Carbon::parse($toInput)->utc() : now()->utc()->addWeek();
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
}
