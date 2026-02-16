<?php

namespace App\Http\Controllers;

use App\Models\SqsMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str; // Add this line
use SellingPartnerApi\SellingPartnerApi;
use App\Services\RegionConfigService;
use App\Services\MarketplaceService;
use Illuminate\Support\Facades\Log;
use App\Services\SqsMessageProcessor;

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

    public function downloadReportDocument(int $id)
    {
        $message = SqsMessage::findOrFail($id);
        $report = $this->extractReportDocumentData($message);

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

                $response = $connector
                    ->reportsV20210630()
                    ->getReportDocument($reportDocumentId, $reportType);

                if ($response->status() >= 400) {
                    $lastError = "HTTP {$response->status()}";
                    continue;
                }

                $url = trim((string) ($response->json('url') ?? ''));
                if ($url === '') {
                    $lastError = 'Report document URL missing in SP-API response.';
                    continue;
                }

                return redirect()->away($url);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('SQS report document download attempt failed', [
                    'message_id' => $message->id,
                    'region' => $region,
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
}
