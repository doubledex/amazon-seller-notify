<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use SellingPartnerApi\SellingPartnerApi;
use SellingPartnerApi\Enums\Endpoint;
use Illuminate\Support\Facades\Log;
// use function config;
use SellingPartnerApi\Seller\NotificationsV1\Dto\DestinationResourceSpecification;
use SellingPartnerApi\Seller\NotificationsV1\Dto\CreateDestinationRequest;
use SellingPartnerApi\Seller\NotificationsV1\Dto\SqsResource;
use SellingPartnerApi\Seller\NotificationsV1\Dto\CreateSubscriptionRequest;

class NotificationSubscriptions extends Controller
{
    private $connector;

    public function __construct()
    {
        $endpoint = config('services.amazon_sp_api.endpoint', 'EU');
        $endpointEnum = Endpoint::tryFrom($endpoint) ?? Endpoint::EU;

        $this->connector = SellingPartnerApi::seller(
            clientId: config('services.amazon_sp_api.client_id'),
            clientSecret: config('services.amazon_sp_api.client_secret'),
            refreshToken: config('services.amazon_sp_api.refresh_token'),
            endpoint: $endpointEnum
        );
    }

    public function index(Request $request)
    {
        $notificationsApi = $this->connector->notificationsV1();
        $notificationTypes = [
            'ACCOUNT_STATUS_CHANGED', 'ANY_OFFER_CHANGED', 'B2B_ANY_OFFER_CHANGED', 'BRANDED_ITEM_CONTENT_CHANGE',
            'FBA_INVENTORY_AVAILABILITY_CHANGES', 'FBA_OUTBOUND_SHIPMENT_STATUS',
            'FEE_PROMOTION', 'FEED_PROCESSING_FINISHED', 'FULFILLMENT_ORDER_STATUS',
            'ITEM_PRODUCT_TYPE_CHANGE', 'LISTINGS_ITEM_STATUS_CHANGE', 'LISTINGS_ITEM_ISSUES_CHANGE',
            'LISTINGS_ITEM_MFN_QUANTITY_CHANGE', 'ORDER_CHANGE', 'PRICING_HEALTH', 'PRODUCT_TYPE_DEFINITIONS_CHANGE',
            'REPORT_PROCESSING_FINISHED'
        ];

        try {
            $responses = $this->fetchSubscriptions($notificationsApi, $notificationTypes, $request);
            $destinationsResponse = $notificationsApi->getDestinations();
            return view('notifications.index', [
                'responses' => $responses,
                'notifications' => $responses['notificationData']['payload'] ?? [],
                'destinations' => $destinationsResponse->json()['payload'] ?? [],
                'responseHeaders' => $responses['responseHeaders'],
                'notificationTypes' => $notificationTypes,
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e, 'Error fetching notifications. Please try again later.');
        }
    }

    private function fetchSubscriptions($notificationsApi, $notificationTypes, $request)
    {
        $responses = [];
        $responseHeaders = [];
        foreach ($notificationTypes as $notificationType) {
            try {
                $response = $notificationsApi->getSubscription($notificationType);

                $responses[$notificationType] = $response->json()['payload'] ?? []; // Using notificationType as key

                // $responses [] = [
                //     'notificationType' => $notificationType,
                //     'response' => $response->json()['payload'] ?? [],
                // ];

                if ($response->status() >= 400) {
                    throw new \Exception("Amazon SP-API Error: " . $response->body());
                }

                $responseHeaders = $response->headers()->all();
                $notificationData = $response->json();
                $nextToken = $notificationData['payload']['NextToken'] ?? null;
                if ($nextToken) {
                    $request->session()->put('next_token', $nextToken);
                }

            } catch (\Exception $e) {
                $this->handleSubscriptionException($e, $notificationType);
            }
            $this->applyRateLimit($responseHeaders);
        }
        // echo json_encode($responses);
        // dd($responses);

        return ['responses' => $responses, 'responseHeaders' => $responseHeaders];

    }

    private function applyRateLimit($responseHeaders)
    {
        $rateLimit = $responseHeaders['x-amzn-RateLimit-Limit'][0] ?? 1;
        $delay = (1 / $rateLimit) * 1000000;
        usleep($delay);
    }

    private function handleSubscriptionException($e, $notificationType)
    {
        $code = $e->getCode();
        if ($code == 0 && $e->getPrevious()) {
            $code = $e->getPrevious()->getCode();
        }

        if ($code == 404) {
            Log::info("No subscription found for notification type: " . $notificationType);
        } elseif ($code == 429) {
            Log::error("Amazon SP-API Quota Exceeded: " . $e->getMessage());
            throw new \Exception('You have exceeded your request quota. Please try again later.');
        } else {
            Log::error("Exception: " . $e->getMessage());
            throw new \Exception('Error fetching notifications. Please try again later.');
        }
    }

    public function storeDestination(Request $request)
    {
        $request->validate([
            'sqsArn' => 'required|string',
            'name' => 'required|string',
        ]);

        try {
            $sqsResource = new SqsResource($request->input('sqsArn'));
            $resourceSpecification = new DestinationResourceSpecification($sqsResource);
            $destinationRequest = new CreateDestinationRequest($resourceSpecification, $request->input('name'));

            $notificationsApi = $this->connector->notificationsV1();
            $notificationResponse = $notificationsApi->createDestination($destinationRequest);

            return back()->with('success', 'Destination created successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while creating the destination.');
        }
    }

    public function deleteDestination(Request $request)
    {
        $request->validate([
            'destinationId' => 'required|string',
            
        ]);
        
        try {
            $destinationId = $request->input('destinationId');
            $notificationsApi = $this->connector->notificationsV1();
            $notificationResponse = $notificationsApi->deleteDestination($destinationId);

            return back()->with('success', 'Destination deleted successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while deleting the destination.');
        }
    }

    public function deleteSubscription(Request $request)
    {
        $request->validate([
            'subscriptionId' => 'required|string',
        ]);
        try {
            $subscriptionId = $request->input('subscriptionId');
            $notificationType = $request->input('notificationType');
            $notificationsApi = $this->connector->notificationsV1();
            $notificationResponse = $notificationsApi->deleteSubscriptionbyId($subscriptionId, $notificationType);

            return back()->with('success', 'Subscription deleted successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while deleting the subscription.');
        }
    }

    public function createSubscription(Request $request)
    {
        $request->validate([
            'notificationType' => 'required|string',
            'destinationId' => 'required|string',
            'payloadVersion' => 'required|string',
        ]);

        try {
            $notificationType = $request->input('notificationType');
            $destinationId = $request->input('destinationId');
            $payloadVersion = $request->input('payloadVersion');

            $createSubscriptionRequest = new CreateSubscriptionRequest($payloadVersion, $destinationId);
            $notificationsApi = $this->connector->notificationsV1();
            $notificationResponse = $notificationsApi->createSubscription($notificationType, $createSubscriptionRequest);

            return back()->with('success', 'Subscription created successfully.');
        } catch (\Exception $e) {
            return $this->handleException($e, 'An error occurred while creating the subscription.');
        }
    }

    private function handleException(\Exception $e, $defaultMessage)
    {
        Log::error("Exception: " . $e->getMessage());
        return back()->with('error', $defaultMessage . ' ' . $e->getMessage());
    }
}