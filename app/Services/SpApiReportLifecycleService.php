<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use SellingPartnerApi\Seller\ReportsV20210630\Dto\CreateReportSpecification;

class SpApiReportLifecycleService
{
    private const CREATE_REPORT_MAX_RETRIES = 8;
    private const CREATE_REPORT_BASE_BACKOFF_SECONDS = 15;

    public function createReportWithRetry(
        object $reportsApi,
        CreateReportSpecification $specification,
        array $logContext = []
    ): array {
        $reportId = '';
        $lastError = null;
        $createPayload = null;

        for ($attempt = 0; $attempt < self::CREATE_REPORT_MAX_RETRIES; $attempt++) {
            try {
                $createResponse = $reportsApi->createReport($specification);
                $createPayload = $createResponse->json();
                $reportId = trim((string) ($createPayload['reportId'] ?? ''));
                if ($reportId !== '') {
                    return [
                        'ok' => true,
                        'report_id' => $reportId,
                        'create_payload' => $createPayload,
                    ];
                }

                $lastError = 'No reportId returned from createReport.';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if (!$this->isQuotaExceededError($e)) {
                    break;
                }

                $delay = $this->retryDelaySeconds($attempt);
                Log::warning('SP-API createReport quota retry', array_merge($logContext, [
                    'attempt' => $attempt,
                    'sleep_seconds' => $delay,
                    'error' => $e->getMessage(),
                ]));
                sleep($delay);
            }
        }

        return [
            'ok' => false,
            'report_id' => null,
            'create_payload' => $createPayload,
            'error' => $lastError,
        ];
    }

    public function pollReportUntilTerminal(
        object $reportsApi,
        string $reportId,
        int $maxAttempts,
        int $sleepSeconds,
        bool $capturePolls = false
    ): array {
        $status = 'IN_QUEUE';
        $reportDocumentId = '';
        $reportDate = null;
        $polls = [];

        for ($i = 0; $i < $maxAttempts; $i++) {
            $poll = $reportsApi->getReport($reportId);
            $payload = $poll->json();
            $status = strtoupper((string) ($payload['processingStatus'] ?? 'IN_QUEUE'));
            $reportDocumentId = trim((string) ($payload['reportDocumentId'] ?? ''));

            if ($capturePolls && count($polls) < 20) {
                $polls[] = $payload;
            }

            $completedAt = trim((string) ($payload['processingEndTime'] ?? ''));
            if ($completedAt !== '') {
                try {
                    $reportDate = Carbon::parse($completedAt)->toDateString();
                } catch (\Throwable) {
                    $reportDate = null;
                }
            }

            if ($status === 'DONE' && $reportDocumentId !== '') {
                return [
                    'ok' => true,
                    'processing_status' => $status,
                    'report_document_id' => $reportDocumentId,
                    'report_date' => $reportDate,
                    'polls' => $polls,
                ];
            }

            if (in_array($status, ['DONE_NO_DATA', 'CANCELLED', 'FATAL'], true)) {
                return [
                    'ok' => $status === 'DONE_NO_DATA',
                    'processing_status' => $status,
                    'report_document_id' => $reportDocumentId !== '' ? $reportDocumentId : null,
                    'report_date' => $reportDate,
                    'polls' => $polls,
                ];
            }

            sleep($sleepSeconds);
        }

        return [
            'ok' => false,
            'processing_status' => $status,
            'report_document_id' => $reportDocumentId !== '' ? $reportDocumentId : null,
            'report_date' => $reportDate,
            'polls' => $polls,
            'error' => "Report not ready. Last status {$status}.",
        ];
    }

    public function pollReportOnce(object $reportsApi, string $reportId): array
    {
        $poll = $reportsApi->getReport($reportId);
        $payload = $poll->json();
        $status = strtoupper((string) ($payload['processingStatus'] ?? 'IN_QUEUE'));
        $reportDocumentId = trim((string) ($payload['reportDocumentId'] ?? ''));
        $reportDate = null;
        $completedAt = trim((string) ($payload['processingEndTime'] ?? ''));
        if ($completedAt !== '') {
            try {
                $reportDate = Carbon::parse($completedAt)->toDateString();
            } catch (\Throwable) {
                $reportDate = null;
            }
        }

        return [
            'ok' => true,
            'processing_status' => $status,
            'report_document_id' => $reportDocumentId !== '' ? $reportDocumentId : null,
            'report_date' => $reportDate,
            'payload' => $payload,
        ];
    }

    public function downloadReportRows(
        object $reportsApi,
        string $reportDocumentId,
        string $reportType
    ): array {
        try {
            $documentResponse = $reportsApi->getReportDocument($reportDocumentId, $reportType);
            $documentPayload = $documentResponse->json();
            $documentUrl = trim((string) ($documentPayload['url'] ?? ''));
            $documentUrlSha256 = $documentUrl !== '' ? hash('sha256', $documentUrl) : null;

            $document = $documentResponse->dto();
            $downloaded = $document->download($reportType);
            $rows = $this->normalizeDownloadedRows($downloaded);

            return [
                'ok' => true,
                'rows' => $rows,
                'document_payload' => $documentPayload,
                'report_document_url_sha256' => $documentUrlSha256,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'rows' => [],
                'document_payload' => null,
                'report_document_url_sha256' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizeDownloadedRows(mixed $downloaded): array
    {
        if (is_array($downloaded)) {
            $rows = [];
            foreach ($downloaded as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            return $rows;
        }

        if (is_string($downloaded)) {
            return $this->parseDelimitedText($downloaded);
        }

        return [];
    }

    private function parseDelimitedText(string $text): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($text));
        if (!$lines || count($lines) < 2) {
            return [];
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : ',';
        $headers = str_getcsv($lines[0], $delimiter);
        $headers = array_map(fn ($h) => trim((string) $h), $headers);

        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            $values = str_getcsv($line, $delimiter);
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    $header = 'col_' . ($index + 1);
                }
                $row[$header] = (string) ($values[$index] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function isQuotaExceededError(\Throwable $e): bool
    {
        $msg = strtoupper($e->getMessage());
        return str_contains($msg, '429')
            || str_contains($msg, 'QUOTAEXCEEDED')
            || str_contains($msg, 'TOO MANY REQUESTS');
    }

    private function retryDelaySeconds(int $attempt): int
    {
        $exp = self::CREATE_REPORT_BASE_BACKOFF_SECONDS * (2 ** $attempt);
        $cap = min(300, $exp);
        return max(1, $cap + random_int(0, 7));
    }
}
