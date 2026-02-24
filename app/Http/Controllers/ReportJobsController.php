<?php

namespace App\Http\Controllers;

use App\Models\ReportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReportJobOrchestrator;

class ReportJobsController extends Controller
{
    public function index(Request $request)
    {
        $provider = trim((string) $request->query('provider', ''));
        $processor = trim((string) $request->query('processor', ''));
        $status = trim((string) $request->query('status', ''));
        $region = strtoupper(trim((string) $request->query('region', '')));
        $marketplace = trim((string) $request->query('marketplace', ''));
        $reportType = trim((string) $request->query('report_type', ''));
        $scope = trim((string) $request->query('scope', 'outstanding'));

        $query = ReportJob::query();

        if ($scope === 'outstanding') {
            $query->whereNull('completed_at');
        }
        if ($provider !== '') {
            $query->where('provider', $provider);
        }
        if ($processor !== '') {
            $query->where('processor', $processor);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($region !== '') {
            $query->whereRaw('upper(coalesce(region, "")) = ?', [$region]);
        }
        if ($marketplace !== '') {
            $query->where('marketplace_id', $marketplace);
        }
        if ($reportType !== '') {
            $query->where('report_type', $reportType);
        }

        $rows = $query
            ->orderByDesc(DB::raw('coalesce(completed_at, created_at)'))
            ->orderByDesc('id')
            ->paginate(100)
            ->appends($request->query());

        $statusSummary = ReportJob::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        return view('reports.jobs', [
            'rows' => $rows,
            'statusSummary' => $statusSummary,
            'provider' => $provider,
            'processor' => $processor,
            'status' => $status,
            'region' => $region,
            'marketplace' => $marketplace,
            'reportType' => $reportType,
            'scope' => $scope === 'all' ? 'all' : 'outstanding',
        ]);
    }

    public function pollNow(Request $request, ReportJobOrchestrator $orchestrator)
    {
        $provider = strtolower(trim((string) $request->input('provider', ReportJobOrchestrator::PROVIDER_SP_API_SELLER)));
        if ($provider !== ReportJobOrchestrator::PROVIDER_SP_API_SELLER) {
            return redirect()
                ->route('reports.jobs', $request->only(['scope', 'provider', 'processor', 'status', 'region', 'marketplace', 'report_type']))
                ->with('error', "Unsupported provider '{$provider}'.");
        }

        $limit = max(1, (int) $request->input('limit', 100));
        $processor = trim((string) $request->input('processor', ''));
        $region = trim((string) $request->input('region', ''));
        $marketplace = trim((string) $request->input('marketplace', ''));
        $reportType = trim((string) $request->input('report_type', ''));

        $result = $orchestrator->pollSpApiSellerJobs(
            $limit,
            $processor !== '' ? $processor : null,
            $region !== '' ? $region : null,
            $marketplace !== '' ? [$marketplace] : null,
            $reportType !== '' ? $reportType : null
        );

        $message = implode(' ', [
            'Report polling complete.',
            'Checked: ' . (int) ($result['checked'] ?? 0),
            'Processed: ' . (int) ($result['processed'] ?? 0),
            'Failed: ' . (int) ($result['failed'] ?? 0),
            'Outstanding: ' . (int) ($result['outstanding'] ?? 0),
        ]);

        return redirect()
            ->route('reports.jobs', $request->only(['scope', 'provider', 'processor', 'status', 'region', 'marketplace', 'report_type']))
            ->with('status', $message);
    }
}
