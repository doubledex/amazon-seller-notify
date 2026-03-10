<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use SpApi\ApiException;

class FinancesAdapter
{
    private array $financesApisByRegion = [];

    public function __construct(
        private readonly ?OfficialSpApiService $officialSpApiService = null,
        private readonly ?RegionConfigService $regionConfigService = null
    ) {
    }

    public function listTransactionsByOrderId(
        string $orderId,
        ?string $marketplaceId = null,
        ?string $nextToken = null,
        ?string $region = null
    ): array {
        $financesApi = $this->financesApiForRegion($region);
        if ($financesApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Finances API client.'];
        }

        try {
            [$model, $status, $headers] = $financesApi->listTransactionsWithHttpInfo(
                posted_after: null,
                posted_before: null,
                marketplace_id: $marketplaceId,
                transaction_status: null,
                related_identifier_name: 'ORDER_ID',
                related_identifier_value: $orderId,
                next_token: $nextToken
            );

            return [
                'status' => (int) $status,
                'headers' => (array) $headers,
                'body' => $this->modelToArray($model),
            ];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        }
    }

    public function listTransactions(
        ?\DateTimeInterface $postedAfter,
        ?\DateTimeInterface $postedBefore,
        ?string $marketplaceId = null,
        ?string $transactionStatus = null,
        ?string $nextToken = null,
        ?string $region = null
    ): array {
        $financesApi = $this->financesApiForRegion($region);
        if ($financesApi === null) {
            return ['status' => 500, 'headers' => [], 'body' => [], 'error' => 'Unable to construct official Finances API client.'];
        }

        try {
            [$model, $status, $headers] = $financesApi->listTransactionsWithHttpInfo(
                posted_after: $this->toDateTime($postedAfter),
                posted_before: $this->toDateTime($postedBefore),
                marketplace_id: $marketplaceId,
                transaction_status: $transactionStatus,
                related_identifier_name: null,
                related_identifier_value: null,
                next_token: $nextToken
            );

            return [
                'status' => (int) $status,
                'headers' => (array) $headers,
                'body' => $this->modelToArray($model),
            ];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        }
    }

    private function financesApiForRegion(?string $region): mixed
    {
        $regionService = $this->regionConfigService ?? new RegionConfigService();
        $resolvedRegion = strtoupper(trim((string) ($region ?: $regionService->defaultSpApiRegion())));
        if (!in_array($resolvedRegion, ['EU', 'NA', 'FE'], true)) {
            $resolvedRegion = $regionService->defaultSpApiRegion();
        }

        if (!isset($this->financesApisByRegion[$resolvedRegion])) {
            $officialService = $this->officialSpApiService ?? new OfficialSpApiService($regionService);
            $this->financesApisByRegion[$resolvedRegion] = $officialService->makeFinancesV20240619Api($resolvedRegion);
        }

        return $this->financesApisByRegion[$resolvedRegion];
    }

    private function toDateTime(?\DateTimeInterface $value): ?\DateTime
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTime) {
            return $value;
        }

        try {
            return new \DateTime($value->format('c'));
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
