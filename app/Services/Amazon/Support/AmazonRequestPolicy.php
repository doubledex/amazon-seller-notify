<?php

namespace App\Services\Amazon\Support;

use Illuminate\Support\Facades\Log;
use Saloon\Http\Response;

class AmazonRequestPolicy
{
    public function execute(string $operation, callable $callback, int $maxAttempts = 4): mixed
    {
        $attempt = 0;
        $maxAttempts = max(1, $maxAttempts);

        beginning:
        $attempt++;

        try {
            $result = $callback();

            if (!$result instanceof Response) {
                return $result;
            }

            if (!$this->shouldRetryStatus($result->status()) || $attempt >= $maxAttempts) {
                return $result;
            }

            $this->logRetry($operation, $attempt, $result->status(), $result);
            usleep($this->delayMicroseconds($attempt, $result));
            goto beginning;
        } catch (\Throwable $e) {
            if ($attempt >= $maxAttempts) {
                throw $e;
            }

            Log::warning('Amazon API request retry after exception', [
                'operation' => $operation,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            usleep($this->delayMicroseconds($attempt));
            goto beginning;
        }
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function delayMicroseconds(int $attempt, ?Response $response = null): int
    {
        $headerReset = null;
        if ($response) {
            $headerReset = trim((string) ($response->header('x-amzn-ratelimit-reset')
                ?? $response->header('x-amzn-RateLimit-Reset')
                ?? ''));
        }

        if ($headerReset !== '' && is_numeric($headerReset)) {
            return (int) max(100_000, (float) $headerReset * 1_000_000);
        }

        $baseMs = 200;
        $delayMs = $baseMs * (2 ** max(0, $attempt - 1));
        $jitterMs = random_int(0, 100);

        return (int) (($delayMs + $jitterMs) * 1000);
    }

    private function logRetry(string $operation, int $attempt, int $status, Response $response): void
    {
        Log::warning('Amazon API quota/server retry', [
            'operation' => $operation,
            'attempt' => $attempt,
            'status' => $status,
            'request_id' => $this->extractRequestId($response),
            'rate_limit_limit' => $response->header('x-amzn-ratelimit-limit') ?? $response->header('x-amzn-RateLimit-Limit'),
            'rate_limit_remaining' => $response->header('x-amzn-ratelimit-remaining') ?? $response->header('x-amzn-RateLimit-Remaining'),
            'rate_limit_reset' => $response->header('x-amzn-ratelimit-reset') ?? $response->header('x-amzn-RateLimit-Reset'),
        ]);
    }

    private function extractRequestId(Response $response): ?string
    {
        $value = (string) ($response->header('x-amzn-requestid')
            ?? $response->header('x-amzn-request-id')
            ?? '');

        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
