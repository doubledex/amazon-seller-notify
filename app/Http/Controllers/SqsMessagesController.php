<?php

namespace App\Http\Controllers;

use App\Models\SqsMessage;
use Illuminate\Http\Request;
use SellingPartnerApi\SellingPartnerApi;
use App\Services\RegionConfigService;
use App\Services\MarketplaceService;
use Illuminate\Support\Facades\Log;
use App\Services\SqsMessageProcessor;
use App\Services\SpApiReportLifecycleService;

class SqsMessagesController extends Controller
{
    public function index(Request $request)
    {
        // $messages = SqsMessage::paginate(10);
        $messages = SqsMessage::orderBy('EventTime', 'desc')->paginate(60);
        return view('sqs_messages.index', [ 'messages' => $messages  ]); // removed compact()
    }

    public function show($id)
    {
        $message = SqsMessage::findOrFail($id);
        $marketplaceService = new MarketplaceService();
        $marketplaceMap = $marketplaceService->getMarketplaceMap();

        return view('sqs_messages.show', [
            'message' => $message,
            'marketplaceMap' => $marketplaceMap,
        ]); // removed compact('message'));
    }

    public function flag($id)
    {
        $message = SqsMessage::findOrFail($id);
        $message->flagged = true;
        $message->save();
        return redirect()->route('sqs_messages.index')->with('success', 'Message flagged.');
    }

    public function destroy($id)
    {
        $message = SqsMessage::findOrFail($id);
        $message->delete();
        return redirect()->route('sqs_messages.index')->with('success', 'Message deleted.');
    }

    public function fetchLatest(SqsMessageProcessor $processor)
    {
        try {
            $stats = $processor->processMessages();
            return redirect()->route('sqs_messages.index')->with('success', $this->formatFetchStatsMessage($stats));
        } catch (\Exception $e) {
            Log::error('SQS fetch failed', ['error' => $e->getMessage()]);
            return redirect()->route('sqs_messages.index')->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function downloadReportDocument(int $id, SpApiReportLifecycleService $reportLifecycle)
    {
        $message = SqsMessage::findOrFail($id);
        $report = $this->extractReportDocumentData($message);
        $format = strtolower(trim((string) request('format', 'raw')));
        if (!in_array($format, ['raw', 'csv', 'xml', 'excel', 'xls'], true)) {
            $format = 'raw';
        }
        if ($format === 'xls') {
            $format = 'excel';
        }

        if ($report === null) {
            return redirect()
                ->route('sqs_messages.index')
                ->with('error', 'No report document is attached to this message.');
        }

        [$reportDocumentId, $reportType] = $report;

        $regionService = new RegionConfigService();
        $regions = $regionService->spApiRegions();

        $lastError = null;
        foreach ($regions as $region) {
            try {
                $config = $regionService->spApiConfig($region);
                if (
                    trim((string) ($config['client_id'] ?? '')) === '' ||
                    trim((string) ($config['client_secret'] ?? '')) === '' ||
                    trim((string) ($config['refresh_token'] ?? '')) === ''
                ) {
                    continue;
                }

                $connector = SellingPartnerApi::seller(
                    clientId: (string) $config['client_id'],
                    clientSecret: (string) $config['client_secret'],
                    refreshToken: (string) $config['refresh_token'],
                    endpoint: $regionService->spApiEndpointEnum($region)
                );

                $reportsApi = $connector->reportsV20210630();
                $meta = $reportLifecycle->getReportDocumentMetadata($reportsApi, $reportDocumentId, $reportType);
                if (!($meta['ok'] ?? false)) {
                    $lastError = (string) ($meta['error'] ?? 'Unable to fetch report document metadata.');
                    continue;
                }

                if ($format === 'raw') {
                    $url = trim((string) ($meta['document_url'] ?? ''));
                    if ($url === '') {
                        $lastError = 'Report document URL missing in SP-API response.';
                        continue;
                    }

                    return redirect()->away($url);
                }

                $download = $reportLifecycle->downloadReportRows($reportsApi, $reportDocumentId, $reportType);
                if (!($download['ok'] ?? false)) {
                    $lastError = (string) ($download['error'] ?? 'Unable to download report document.');
                    continue;
                }
                $rows = is_array($download['rows'] ?? null) ? $download['rows'] : [];

                if ($format === 'csv') {
                    $csv = $this->rowsToCsv($rows);
                    $filename = $this->buildDownloadFileName($reportType, $reportDocumentId, 'csv');

                    return response($csv, 200, [
                        'Content-Type' => 'text/csv; charset=UTF-8',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]);
                }

                if ($format === 'excel') {
                    $excelHtml = $this->rowsToExcelHtml($rows, $reportType);
                    $filename = $this->buildDownloadFileName($reportType, $reportDocumentId, 'xls');

                    return response($excelHtml, 200, [
                        'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                    ]);
                }

                $xml = $this->rowsToXml($rows, $reportType, $reportDocumentId);
                $filename = $this->buildDownloadFileName($reportType, $reportDocumentId, 'xml');

                return response($xml, 200, [
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('SQS report document download attempt failed', [
                    'message_id' => $message->id,
                    'region' => $region,
                    'format' => $format,
                    'report_document_id' => $reportDocumentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()
            ->route('sqs_messages.index')
            ->with('error', 'Unable to fetch report document URL. ' . ($lastError ? "Last error: {$lastError}" : ''));
    }

    private function formatFetchStatsMessage(array $stats): string
    {
        if (!empty($stats['no_messages'])) {
            return 'No new SQS messages available.';
        }

        $parts = [
            'SQS fetch complete.',
            'Received: ' . (int) ($stats['received'] ?? 0),
            'Stored: ' . (int) ($stats['stored'] ?? 0),
            'Updated: ' . (int) ($stats['updated'] ?? 0),
            'Deleted: ' . (int) ($stats['deleted'] ?? 0),
            'Duplicates deleted: ' . (int) ($stats['duplicates_deleted'] ?? 0),
            'Invalid JSON: ' . (int) ($stats['invalid_json'] ?? 0),
            'Errors: ' . (int) ($stats['errors'] ?? 0),
        ];

        $before = $stats['queue_before'] ?? null;
        $after = $stats['queue_after'] ?? null;
        if (is_array($before) || is_array($after)) {
            $beforeVisible = is_array($before) ? (int) ($before['visible'] ?? 0) : null;
            $afterVisible = is_array($after) ? (int) ($after['visible'] ?? 0) : null;
            if ($beforeVisible !== null || $afterVisible !== null) {
                $parts[] = 'Queue visible: ' . ($beforeVisible ?? 'n/a') . ' -> ' . ($afterVisible ?? 'n/a');
            }
        }

        return implode(' ', $parts);
    }

    private function extractReportDocumentData(SqsMessage $message): ?array
    {
        $body = json_decode((string) $message->body, true);
        if (!is_array($body)) {
            return null;
        }

        $rootPayload = isset($body['payload']) && is_array($body['payload'])
            ? $body['payload']
            : (isset($body['Payload']) && is_array($body['Payload']) ? $body['Payload'] : []);

        $reportNode = isset($rootPayload['reportProcessingFinishedNotification']) && is_array($rootPayload['reportProcessingFinishedNotification'])
            ? $rootPayload['reportProcessingFinishedNotification']
            : (isset($rootPayload['ReportProcessingFinishedNotification']) && is_array($rootPayload['ReportProcessingFinishedNotification'])
                ? $rootPayload['ReportProcessingFinishedNotification']
                : []);

        $documentId = trim((string) ($reportNode['reportDocumentId'] ?? $reportNode['ReportDocumentId'] ?? ''));
        $reportType = trim((string) ($reportNode['reportType'] ?? $reportNode['ReportType'] ?? ''));

        if ($documentId === '' || $reportType === '') {
            return null;
        }

        return [$documentId, $reportType];
    }


    private function rowsToCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $headers[$key] = true;
            }
        }
        $headers = array_keys($headers);

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = (string) ($row[$header] ?? '');
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return (string) $csv;
    }

    private function rowsToXml(array $rows, string $reportType, string $reportDocumentId): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><report/>');
        $xml->addAttribute('type', $reportType);
        $xml->addAttribute('document_id', $reportDocumentId);

        foreach ($rows as $row) {
            $rowNode = $xml->addChild('row');
            foreach ($row as $key => $value) {
                $field = $rowNode->addChild('field');
                $field->addAttribute('name', (string) $key);
                $field[0] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return (string) $xml->asXML();
    }

    private function rowsToExcelHtml(array $rows, string $reportType): string
    {
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $headers[$key] = true;
            }
        }
        $headers = array_keys($headers);

        $title = htmlspecialchars($reportType, ENT_QUOTES, 'UTF-8');
        $html = '<html><head><meta charset="UTF-8"><title>' . $title . '</title></head><body>';
        $html .= '<table border="1"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . htmlspecialchars((string) $header, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = (string) ($row[$header] ?? '');
                $html .= '<td>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody></table></body></html>';
        return $html;
    }

    private function buildDownloadFileName(string $reportType, string $reportDocumentId, string $extension): string
    {
        $safeType = preg_replace('/[^A-Za-z0-9_\-]/', '_', $reportType);
        $safeDoc = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $reportDocumentId);
        return "{$safeType}_{$safeDoc}.{$extension}";
    }
}
