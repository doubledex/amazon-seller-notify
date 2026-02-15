<?php

namespace App\Http\Controllers;

use App\Models\AmazonAdsReportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class AdsReportController extends Controller
{
    public function index(Request $request)
    {
        $scope = strtolower((string) $request->query('scope', 'outstanding'));
        if (!in_array($scope, ['outstanding', 'all'], true)) {
            $scope = 'outstanding';
        }

        $status = strtoupper(trim((string) $request->query('status', '')));
        $profileId = trim((string) $request->query('profile_id', ''));
        $adProduct = strtoupper(trim((string) $request->query('ad_product', '')));
        $httpStatus = trim((string) $request->query('http_status', ''));
        $stuckOnly = (string) $request->query('stuck_only', '0') === '1';

        $query = AmazonAdsReportRequest::query()->orderByDesc('requested_at')->orderByDesc('id');

        if ($scope === 'outstanding') {
            $query->whereNull('processed_at')->whereNotIn('status', ['FAILED', 'CANCELLED']);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($profileId !== '') {
            $query->where('profile_id', $profileId);
        }

        if ($adProduct !== '') {
            $query->where('ad_product', $adProduct);
        }

        if ($httpStatus !== '') {
            $query->where('last_http_status', $httpStatus);
        }

        if ($stuckOnly) {
            $query->whereNotNull('stuck_alerted_at');
        }

        $rows = $query->paginate(100)->appends($request->query());

        return view('ads.reports', [
            'rows' => $rows,
            'scope' => $scope,
            'status' => $status,
            'profileId' => $profileId,
            'adProduct' => $adProduct,
            'httpStatus' => $httpStatus,
            'stuckOnly' => $stuckOnly,
        ]);
    }

    public function pollNow(Request $request)
    {
        try {
            Artisan::call('ads:poll-reports', [
                '--limit' => 200,
            ]);

            $output = trim(Artisan::output());
            $message = $output !== '' ? $output : 'Ads report poll completed.';
            return redirect()->back()->with('status', $message);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Poll failed: ' . $e->getMessage());
        }
    }
}
