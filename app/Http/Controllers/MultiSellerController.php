<?php

namespace App\Http\Controllers;
include 'vendor/jlevers/selling-partner-api/SellingPartnerApi.php';

use Saloon\Exceptions\Request\RequestException;

use Illuminate\Http\JsonResponse;
use HighsideLabs\LaravelSpApi\Models\Credentials;
use SellingPartnerApi;
use SellingPartnerApi\Seller\SellersV1\Api as SellersApi;



// use Saloon\Http\Response;
// use Saloon\Exceptions;
// use Saloon\Exceptions\Request;
// use Saloon\Exceptions\RequestException;
// use Saloon\Exceptions\Request\RequestException
// use SellingPartnerApi\SellingPartnerApi;
// use SellingPartnerApi\Api\SellersV1Api as SellersApi;
// use SellingPartnerApi\ApiException;

class MultiSellerController extends Controller
{
    public function injected(SellersApi $api): JsonResponse
    {
        // Retrieve a set of credentials
        $creds = Credentials::first();
        // Inject the credentials into the API class instance
        $creds->useOn($api);

        try {
            // Now we can make SP API calls!
            $result = $api->getMarketplaceParticipations();
            return response()->json($result);
        } catch (RequestException $e) {
            $jsonBody = json_decode($e->getResponse()->getBody()->getContents());
            return response()->json($jsonBody, $e->getCode());
        }
    }

    public function manual(): JsonResponse
    {
        // Retrieve the credentials we want to use to make the API call
        $creds = Credentials::first();
        // Generate an instance of a particular API class, already populated with the creds we retrieved above
        $api = SellingPartnerApi::makeApi(SellersApi::class, $creds);

        try {
            // Then make an SP API call!
            $result = $api->getMarketplaceParticipations();
            return response()->json($result);
        } catch (RequestException $e) {
            $jsonBody = json_decode($e->getResponse()->getBody()->getContents());
            return response()->json($jsonBody, $e->getCode());
        }
    }
}
