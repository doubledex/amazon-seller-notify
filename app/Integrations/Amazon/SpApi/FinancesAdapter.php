<?php

namespace App\Integrations\Amazon\SpApi;

use Saloon\Http\Response;

class FinancesAdapter
{
    private array $financesApisByRegion = [];

    public function __construct(private readonly SpApiClientFactory $clientFactory)
    {}

    public function listTransactionsByOrderId(
        string $orderId,
        ?string $marketplaceId = null,
        ?string $nextToken = null,
        ?string $region = null
    ): Response {
        $financesApi = $this->financesApiForRegion($region);

        return $financesApi->listTransactions(
            postedAfter: null,
            postedBefore: null,
            marketplaceId: $marketplaceId,
            transactionStatus: null,
            relatedIdentifierName: 'ORDER_ID',
            relatedIdentifierValue: $orderId,
            nextToken: $nextToken
        );
    }

    private function financesApiForRegion(?string $region): mixed
    {
        $region = strtoupper(trim((string) $region));
        if (!in_array($region, ['EU', 'NA', 'FE'], true)) {
            $region = '';
        }

        if (!isset($this->financesApisByRegion[$region])) {
            $connector = $this->clientFactory->makeSellerConnector($region !== '' ? $region : null);
            $this->financesApisByRegion[$region] = $connector->financesV20240619();
        }

        return $this->financesApisByRegion[$region];
    }
}
