<?php

namespace App\Integrations\Amazon\SpApi;

use App\Services\Amazon\OfficialSpApiService;
use App\Services\RegionConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
        } catch (\InvalidArgumentException $e) {
            if ($this->isFinancesEnumDeserializationError($e)) {
                return $this->listTransactionsRaw(
                    $financesApi,
                    postedAfter: null,
                    postedBefore: null,
                    marketplaceId: $marketplaceId,
                    transactionStatus: null,
                    relatedIdentifierName: 'ORDER_ID',
                    relatedIdentifierValue: $orderId,
                    nextToken: $nextToken
                );
            }

            return [
                'status' => 500,
                'headers' => [],
                'body' => [],
                'error' => $e->getMessage(),
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
        } catch (\InvalidArgumentException $e) {
            if ($this->isFinancesEnumDeserializationError($e)) {
                return $this->listTransactionsRaw(
                    $financesApi,
                    postedAfter: $this->toDateTime($postedAfter),
                    postedBefore: $this->toDateTime($postedBefore),
                    marketplaceId: $marketplaceId,
                    transactionStatus: $transactionStatus,
                    relatedIdentifierName: null,
                    relatedIdentifierValue: null,
                    nextToken: $nextToken
                );
            }

            return [
                'status' => 500,
                'headers' => [],
                'body' => [],
                'error' => $e->getMessage(),
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

    private function isFinancesEnumDeserializationError(\InvalidArgumentException $e): bool
    {
        $message = strtoupper(trim($e->getMessage()));

        return str_contains($message, 'ITEM_RELATED_IDENTIFIER_NAME')
            && str_contains($message, 'INVALID VALUE');
    }

    private function listTransactionsRaw(
        mixed $financesApi,
        ?\DateTime $postedAfter,
        ?\DateTime $postedBefore,
        ?string $marketplaceId,
        ?string $transactionStatus,
        ?string $relatedIdentifierName,
        ?string $relatedIdentifierValue,
        ?string $nextToken
    ): array {
        try {
            $request = $financesApi->listTransactionsRequest(
                posted_after: $postedAfter,
                posted_before: $postedBefore,
                marketplace_id: $marketplaceId,
                transaction_status: $transactionStatus,
                related_identifier_name: $relatedIdentifierName,
                related_identifier_value: $relatedIdentifierValue,
                next_token: $nextToken
            );
            $request = $financesApi->getConfig()->sign($request);

            $response = (new Client())->send($request);
            $decoded = json_decode((string) $response->getBody(), true);

            return [
                'status' => (int) $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => is_array($decoded) ? $decoded : [],
            ];
        } catch (ApiException $e) {
            return [
                'status' => (int) $e->getCode(),
                'headers' => (array) ($e->getResponseHeaders() ?? []),
                'body' => $this->modelToArray($e->getResponseBody()),
                'error' => $e->getMessage(),
            ];
        } catch (GuzzleException|\Throwable $e) {
            return [
                'status' => 500,
                'headers' => [],
                'body' => [],
                'error' => $e->getMessage(),
            ];
        }
    }
}
