<?php

namespace App\Http\Controllers;

use App\Models\ReportJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReportJobOrchestrator;
use App\Services\RegionConfigService;
use App\Services\SpApiReportLifecycleService;
use SellingPartnerApi\SellingPartnerApi;

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

    public function downloadCsv(int $id, RegionConfigService $regionConfig, SpApiReportLifecycleService $lifecycle)
    {
        $job = ReportJob::query()->findOrFail($id);
        $documentId = trim((string) ($job->external_document_id ?? ''));
        if ($documentId === '') {
            return redirect()
                ->route('reports.jobs')
                ->with('error', 'This report job has no report document to download.');
        }

        $region = strtoupper(trim((string) ($job->region ?? 'NA')));
        $config = $regionConfig->spApiConfig($region);
        $connector = SellingPartnerApi::seller(
            clientId: (string) $config['client_id'],
            clientSecret: (string) $config['client_secret'],
            refreshToken: (string) $config['refresh_token'],
            endpoint: $regionConfig->spApiEndpointEnum($region)
        );

        $download = $lifecycle->downloadReportRows(
            $connector->reportsV20210630(),
            $documentId,
            (string) $job->report_type
        );
        if (!($download['ok'] ?? false)) {
            return redirect()
                ->route('reports.jobs')
                ->with('error', 'Unable to download report rows. ' . (string) ($download['error'] ?? 'Unknown error.'));
        }

        $rows = is_array($download['rows'] ?? null) ? $download['rows'] : [];
        $csv = $this->rowsToCsv($rows);
        $filename = sprintf('report_job_%d_%s.csv', (int) $job->id, preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $job->report_type));

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function rowsToCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $headers[(string) $key] = true;
            }
        }
        $headers = array_keys($headers);
        if (empty($headers)) {
            return '';
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers, ',', '"', '\\');
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $line = [];
            foreach ($headers as $header) {
                $line[] = (string) ($row[$header] ?? '');
            }
            fputcsv($stream, $line, ',', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }
}
