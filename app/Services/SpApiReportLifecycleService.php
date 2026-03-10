<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SpApi\Api\reports\v2021_06_30\ReportsApi;
use SpApi\ApiException;
use SpApi\Model\reports\v2021_06_30\CreateReportSpecification;

class SpApiReportLifecycleService
{
    private const CREATE_REPORT_MAX_RETRIES = 8;
    private const CREATE_REPORT_BASE_BACKOFF_SECONDS = 15;

    public function createReportWithRetry(
        ReportsApi $reportsApi,
        CreateReportSpecification $specification,
        array $logContext = []
    ): array {
        $reportId = '';
        $lastError = null;
        $createPayload = null;

        for ($attempt = 0; $attempt < self::CREATE_REPORT_MAX_RETRIES; $attempt++) {
            try {
                [$createResponse] = $reportsApi->createReportWithHttpInfo($specification);
                $createPayload = $this->modelToArray($createResponse);
                $reportId = trim((string) ($createResponse?->getReportId() ?? ''));
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
        ReportsApi $reportsApi,
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
            [$reportModel] = $reportsApi->getReportWithHttpInfo($reportId);
            $status = strtoupper((string) ($reportModel?->getProcessingStatus() ?? 'IN_QUEUE'));
            $reportDocumentId = trim((string) ($reportModel?->getReportDocumentId() ?? ''));
            $payload = $this->modelToArray($reportModel);

            if ($capturePolls && count($polls) < 20) {
                $polls[] = $payload;
            }

            $reportDate = $this->normalizeReportDate($reportModel?->getProcessingEndTime());

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

    public function pollReportOnce(ReportsApi $reportsApi, string $reportId): array
    {
        [$reportModel] = $reportsApi->getReportWithHttpInfo($reportId);
        $status = strtoupper((string) ($reportModel?->getProcessingStatus() ?? 'IN_QUEUE'));
        $reportDocumentId = trim((string) ($reportModel?->getReportDocumentId() ?? ''));

        return [
            'ok' => true,
            'processing_status' => $status,
            'report_document_id' => $reportDocumentId !== '' ? $reportDocumentId : null,
            'report_date' => $this->normalizeReportDate($reportModel?->getProcessingEndTime()),
            'payload' => $this->modelToArray($reportModel),
        ];
    }

    public function downloadReportRows(
        ReportsApi $reportsApi,
        string $reportDocumentId,
        string $reportType
    ): array {
        try {
            $meta = $this->getReportDocumentMetadata($reportsApi, $reportDocumentId, $reportType);
            if (!($meta['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'document_payload' => null,
                    'report_document_url_sha256' => null,
                    'error' => (string) ($meta['error'] ?? 'Failed to get report document metadata.'),
                ];
            }

            $documentPayload = is_array($meta['document_payload'] ?? null) ? $meta['document_payload'] : null;
            $documentUrlSha256 = $meta['report_document_url_sha256'] ?? null;
            $url = trim((string) ($meta['document_url'] ?? ''));
            if ($url === '') {
                return [
                    'ok' => false,
                    'rows' => [],
                    'document_payload' => $documentPayload,
                    'report_document_url_sha256' => $documentUrlSha256,
                    'error' => 'Report document URL missing in SP-API response.',
                ];
            }

            $raw = Http::timeout(180)->get($url);
            if (!$raw->successful()) {
                return [
                    'ok' => false,
                    'rows' => [],
                    'document_payload' => $documentPayload,
                    'report_document_url_sha256' => $documentUrlSha256,
                    'error' => 'Unable to download report document payload.',
                ];
            }

            $content = $raw->body();
            $compression = strtoupper(trim((string) ($meta['compression_algorithm'] ?? '')));
            if ($compression === 'GZIP') {
                $decoded = @gzdecode($content);
                if ($decoded === false) {
                    return [
                        'ok' => false,
                        'rows' => [],
                        'document_payload' => $documentPayload,
                        'report_document_url_sha256' => $documentUrlSha256,
                        'error' => 'Unable to decompress GZIP report document payload.',
                    ];
                }
                $content = $decoded;
            }

            return [
                'ok' => true,
                'rows' => $this->normalizeDownloadedRows($content),
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

    public function getReportDocumentMetadata(
        ReportsApi $reportsApi,
        string $reportDocumentId,
        string $reportType
    ): array {
        try {
            [$document] = $reportsApi->getReportDocumentWithHttpInfo($reportDocumentId);
            $documentPayload = $this->modelToArray($document);
            $documentUrl = trim((string) ($document?->getUrl() ?? ''));
            $documentUrlSha256 = $documentUrl !== '' ? hash('sha256', $documentUrl) : null;

            return [
                'ok' => true,
                'response' => $document,
                'document_payload' => $documentPayload,
                'document_url' => $documentUrl,
                'compression_algorithm' => $document?->getCompressionAlgorithm(),
                'report_document_url_sha256' => $documentUrlSha256,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'response' => null,
                'document_payload' => null,
                'document_url' => null,
                'compression_algorithm' => null,
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
        if ($e instanceof ApiException && (int) $e->getCode() === 429) {
            return true;
        }

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

    private function normalizeReportDate(?\DateTime $value): ?string
    {
        if (!$value instanceof \DateTime) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function modelToArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $json = json_encode($value);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
