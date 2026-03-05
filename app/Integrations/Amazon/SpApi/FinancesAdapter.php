<?php

namespace App\Integrations\Amazon\SpApi;

use Saloon\Http\Response;

class FinancesAdapter
{
    private mixed $connector;
    private mixed $financesApi;

    public function __construct(private readonly SpApiClientFactory $clientFactory)
    {
        $this->connector = $this->clientFactory->makeSellerConnector();
        $this->financesApi = $this->connector->financesV20240619();
    }

    public function listTransactionsByOrderId(
        string $orderId,
        ?string $marketplaceId = null,
        ?string $nextToken = null
    ): Response {
        return $this->financesApi->listTransactions(
            postedAfter: null,
            postedBefore: null,
            marketplaceId: $marketplaceId,
            transactionStatus: null,
            relatedIdentifierName: 'ORDER_ID',
            relatedIdentifierValue: $orderId,
            nextToken: $nextToken
        );
    }
}
